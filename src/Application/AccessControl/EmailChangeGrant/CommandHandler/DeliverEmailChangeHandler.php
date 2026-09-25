<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryAttemptResult;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeDelivered;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeDeliveryNotRetryableException;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class DeliverEmailChangeHandler
 *
 * Claims, invokes, and records one recoverable email-change delivery.
 */
final readonly class DeliverEmailChangeHandler implements CommandHandler
{
    private const string CLAIM_LEASE = 'PT5M';

    /**
     * Constructs DeliverEmailChangeHandler
     */
    public function __construct(
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private EmailChangeDeliveryCipher $emailChangeDeliveryCipher,
        private CredentialDeliveryProvider $credentialDeliveryProvider,
        private Clock $clock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function commandRegistration(): string
    {
        return DeliverEmailChange::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var DeliverEmailChange $command */
        $command = $commandMessage->payload();

        try {
            $claimed = $this->claim($command);
            $claimToken = $claimed->getDelivery()->getClaimToken();
            assert($claimToken instanceof CredentialDeliveryClaimToken);

            $attemptResult = $this->invoke($claimed, $claimToken);
            $successEvent = $this->recordOutcome($command, $claimed, $claimToken, $attemptResult);
            if ($successEvent instanceof EmailChangeDelivered) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            $this->eventDispatcher->trigger(new CommandFailedEvent($command, $throwable->getMessage()));
            throw $throwable;
        }
    }

    /**
     * Acquires one exact delivery claim before external invocation
     */
    private function claim(DeliverEmailChange $command): EmailChangeGrant
    {
        return $this->unitOfWork->commitTransactional(function () use ($command): EmailChangeGrant {
            $grant = $this->emailChangeGrantRepository->getByDeliveryId(
                $command->getEmailChangeDeliveryId()
            );
            $latest = $this->emailChangeGrantRepository->getLatestByUserId($command->getUserId());
            if (!$this->isCurrent($grant, $latest, $command)) {
                throw new EmailChangeDeliveryNotRetryableException(
                    'The email-change delivery work is no longer retryable.'
                );
            }

            $claimedAt = $this->clock->now();
            $claimed = $grant->claimDelivery(
                CredentialDeliveryClaimToken::generate(),
                $claimedAt,
                $this->leaseUntil($claimedAt, $grant->getExpiresAt())
            );
            if (!$this->emailChangeGrantRepository->replace($grant, $claimed)) {
                throw new EmailChangeDeliveryNotRetryableException(
                    'The email-change delivery generation changed concurrently.'
                );
            }

            return $claimed;
        });
    }

    /**
     * Invokes the provider with no transaction open
     */
    private function invoke(
        EmailChangeGrant $claimed,
        CredentialDeliveryClaimToken $claimToken
    ): CredentialDeliveryAttemptResult {
        $delivery = $claimed->getDelivery();

        try {
            $material = $delivery->materialForClaim($claimToken, $this->clock->now());
            $credential = $this->emailChangeDeliveryCipher->decrypt($material);
            $outcome = $this->credentialDeliveryProvider->deliver(new CredentialDeliveryInvocation(
                $claimed->purpose(),
                $delivery->getId()->toString(),
                $delivery->getEmail(),
                $credential
            ));

            return CredentialDeliveryAttemptResult::fromOutcome($outcome);
        } catch (Throwable) {
            return CredentialDeliveryAttemptResult::unexpectedFailure();
        }
    }

    /**
     * Records one matching expected-state outcome after invocation
     */
    private function recordOutcome(
        DeliverEmailChange $command,
        EmailChangeGrant $claimed,
        CredentialDeliveryClaimToken $claimToken,
        CredentialDeliveryAttemptResult $attemptResult
    ): ?EmailChangeDelivered {
        return $this->unitOfWork->commitTransactional(function () use (
            $command,
            $claimed,
            $claimToken,
            $attemptResult
        ): ?EmailChangeDelivered {
            $current = $this->emailChangeGrantRepository->getByDeliveryId(
                $command->getEmailChangeDeliveryId()
            );
            $latest = $this->emailChangeGrantRepository->getLatestByUserId($command->getUserId());
            if (
                !$this->isCurrent($current, $latest, $command)
                || $current->getRevision() !== $claimed->getRevision()
            ) {
                throw new EmailChangeDeliveryNotRetryableException(
                    'The email-change delivery outcome is stale.'
                );
            }

            $occurredAt = $this->clock->now();
            $outcome = $attemptResult->getOutcome();
            $replacement = match ($outcome) {
                CredentialDeliveryOutcome::DELIVERED => $current->confirmDelivery($claimToken, $occurredAt),
                CredentialDeliveryOutcome::RETRYABLE_FAILURE => $current->failDelivery(
                    $claimToken,
                    $occurredAt,
                    $attemptResult->getFailure() ?? CredentialDeliveryFailure::UNEXPECTED_PROVIDER
                ),
                CredentialDeliveryOutcome::PERMANENT_FAILURE => $current->failDeliveryPermanently(
                    $claimToken,
                    $occurredAt
                )
            };
            if (!$this->emailChangeGrantRepository->replace($current, $replacement)) {
                throw new EmailChangeDeliveryNotRetryableException(
                    'The email-change delivery outcome changed concurrently.'
                );
            }

            $action = 'user.email_change_delivery.failed';
            if ($outcome === CredentialDeliveryOutcome::DELIVERED) {
                $action = 'user.email_change_delivery.confirmed';
            }

            $this->auditEvidenceRepository->add(AuditEvidence::record(
                $command->getActorId()->toString(),
                $action,
                $command->getUserId()
            ));

            if ($outcome !== CredentialDeliveryOutcome::DELIVERED) {
                return null;
            }

            return new EmailChangeDelivered(
                $command->getActorId(),
                $command->getUserId(),
                $command->getEmailChangeDeliveryId()
            );
        });
    }

    /**
     * Returns the bounded claim lease ending no later than grant expiry
     */
    private function leaseUntil(DateTimeImmutable $claimedAt, DateTimeImmutable $expiresAt): DateTimeImmutable
    {
        $leaseUntil = $claimedAt->add(new DateInterval(self::CLAIM_LEASE));
        if ($leaseUntil > $expiresAt) {
            return $expiresAt;
        }

        return $leaseUntil;
    }

    /**
     * Returns whether the exact generation remains authoritative
     */
    private function isCurrent(
        ?EmailChangeGrant $grant,
        ?EmailChangeGrant $latest,
        DeliverEmailChange $command
    ): bool {
        return $grant instanceof EmailChangeGrant
            && $latest instanceof EmailChangeGrant
            && $latest->getId()->equals($grant->getId())
            && $latest->getRevision() === $grant->getRevision()
            && $grant->getUserId()->equals($command->getUserId());
    }
}
