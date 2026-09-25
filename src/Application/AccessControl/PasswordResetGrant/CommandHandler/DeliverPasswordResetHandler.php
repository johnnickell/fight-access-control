<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryAttemptResult;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetDeliveryException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Throwable;

/**
 * Class DeliverPasswordResetHandler
 *
 * Claims, invokes, and records one recoverable password-reset delivery.
 */
final readonly class DeliverPasswordResetHandler implements CommandHandler
{
    private const string CLAIM_LEASE = 'PT5M';

    /**
     * Constructs DeliverPasswordResetHandler
     */
    public function __construct(
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private PasswordResetDeliveryCipher $passwordResetDeliveryCipher,
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
        return DeliverPasswordReset::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var DeliverPasswordReset $command */
        $command = $commandMessage->payload();

        try {
            $claimed = $this->claim($command);
            $claimToken = $claimed->getDelivery()->getClaimToken();
            assert($claimToken instanceof CredentialDeliveryClaimToken);

            $attemptResult = $this->invoke($claimed, $claimToken);
            $successEvent = $this->recordOutcome($command, $claimed, $claimToken, $attemptResult);
            if ($successEvent instanceof PasswordResetDeliveryConfirmed) {
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
    private function claim(DeliverPasswordReset $command): PasswordResetGrant
    {
        return $this->unitOfWork->commitTransactional(function () use ($command): PasswordResetGrant {
            $grant = $this->passwordResetGrantRepository->getByDeliveryId(
                $command->getPasswordResetDeliveryId()
            );
            $latest = $this->passwordResetGrantRepository->getLatestByUserId($command->getUserId());
            if (!$this->isCurrent($grant, $latest, $command)) {
                throw new PasswordResetDeliveryException(
                    'The password-reset delivery work is no longer retryable.'
                );
            }

            $claimedAt = $this->clock->now();
            $claimed = $grant->claimDelivery(
                CredentialDeliveryClaimToken::generate(),
                $claimedAt,
                $this->leaseUntil($claimedAt, $grant->getExpiresAt())
            );
            if (!$this->passwordResetGrantRepository->replace($grant, $claimed)) {
                throw new PasswordResetDeliveryException(
                    'The password-reset delivery generation changed concurrently.'
                );
            }

            return $claimed;
        });
    }

    /**
     * Invokes the provider with no transaction open
     */
    private function invoke(
        PasswordResetGrant $claimed,
        CredentialDeliveryClaimToken $claimToken
    ): CredentialDeliveryAttemptResult {
        $delivery = $claimed->getDelivery();

        try {
            $material = $delivery->materialForClaim($claimToken, $this->clock->now());
            $credential = $this->passwordResetDeliveryCipher->decrypt($material);
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
        DeliverPasswordReset $command,
        PasswordResetGrant $claimed,
        CredentialDeliveryClaimToken $claimToken,
        CredentialDeliveryAttemptResult $attemptResult
    ): ?PasswordResetDeliveryConfirmed {
        return $this->unitOfWork->commitTransactional(function () use (
            $command,
            $claimed,
            $claimToken,
            $attemptResult
        ): ?PasswordResetDeliveryConfirmed {
            $current = $this->passwordResetGrantRepository->getByDeliveryId(
                $command->getPasswordResetDeliveryId()
            );
            $latest = $this->passwordResetGrantRepository->getLatestByUserId($command->getUserId());
            if (
                !$this->isCurrent($current, $latest, $command)
                || $current->getRevision() !== $claimed->getRevision()
            ) {
                throw new PasswordResetDeliveryException(
                    'The password-reset delivery outcome is stale.'
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
            if (!$this->passwordResetGrantRepository->replace($current, $replacement)) {
                throw new PasswordResetDeliveryException(
                    'The password-reset delivery outcome changed concurrently.'
                );
            }

            $action = 'user.password_reset_delivery.failed';
            if ($outcome === CredentialDeliveryOutcome::DELIVERED) {
                $action = 'user.password_reset_delivery.confirmed';
            }

            $this->auditEvidenceRepository->add(AuditEvidence::record(
                $command->getActorId(),
                $action,
                $command->getUserId()
            ));

            if ($outcome !== CredentialDeliveryOutcome::DELIVERED) {
                return null;
            }

            return new PasswordResetDeliveryConfirmed(
                $command->getActorId(),
                $command->getUserId(),
                $command->getPasswordResetDeliveryId(),
                $occurredAt
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
        ?PasswordResetGrant $grant,
        ?PasswordResetGrant $latest,
        DeliverPasswordReset $command
    ): bool {
        return $grant instanceof PasswordResetGrant
            && $latest instanceof PasswordResetGrant
            && $latest->getId()->equals($grant->getId())
            && $latest->getRevision() === $grant->getRevision()
            && $grant->getUserId()->equals($command->getUserId());
    }
}
