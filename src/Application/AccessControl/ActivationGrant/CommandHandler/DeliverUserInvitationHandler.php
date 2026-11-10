<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryAttemptResult;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryProvider;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\UserInvitationDelivered;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditCorrelation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Exception\CredentialDeliveryTransitionException;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandHandler;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\MessageId;
use LogicException;
use Throwable;

/**
 * Class DeliverUserInvitationHandler
 *
 * Claims, invokes, and records one recoverable invitation delivery.
 */
final readonly class DeliverUserInvitationHandler implements CommandHandler
{
    private const string CLAIM_LEASE = 'PT5M';

    /**
     * Constructs DeliverUserInvitationHandler
     */
    public function __construct(
        private ActivationGrantRepository $activationGrantRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private InvitationDeliveryCipher $invitationDeliveryCipher,
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
        return DeliverUserInvitation::class;
    }

    /**
     * @inheritDoc
     */
    public function handle(CommandMessage $commandMessage): void
    {
        /** @var DeliverUserInvitation $command */
        $command = $commandMessage->payload();

        try {
            $claimed = $this->claim($command);
            $claimToken = $claimed->getDelivery()->getClaimToken();
            assert($claimToken instanceof CredentialDeliveryClaimToken);

            $outcome = $this->invoke($claimed, $claimToken);
            $successEvent = $this->recordOutcome($command, $claimed, $claimToken, $outcome, $commandMessage->id());
            if ($successEvent instanceof UserInvitationDelivered) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            try {
                // Storage and subscriber diagnostics can contain credential material.
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'User invitation delivery failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }

    /**
     * Acquires one exact delivery claim before external invocation
     */
    private function claim(DeliverUserInvitation $command): ActivationGrant
    {
        /** @var ActivationGrant $claimed */
        $claimed = $this->unitOfWork->commitTransactional(function () use ($command): ActivationGrant {
            $activationGrant = $this->activationGrantRepository->getByDeliveryId(
                $command->getActivationDeliveryId()
            );
            $latest = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
            if (!$this->isCurrent($activationGrant, $latest, $command)) {
                throw new StateConflict(
                    'The activation delivery work is no longer retryable.'
                );
            }

            $claimedAt = $this->clock->now();
            $leaseUntil = $this->leaseUntil($claimedAt, $activationGrant->getExpiresAt());
            try {
                $claimed = $activationGrant->claimDelivery(
                    CredentialDeliveryClaimToken::generate(),
                    $claimedAt,
                    $leaseUntil
                );
            } catch (CredentialDeliveryTransitionException $credentialDeliveryTransitionException) {
                throw new StateConflict(
                    'The activation delivery cannot be claimed.',
                    previous: $credentialDeliveryTransitionException
                );
            }

            if (!$this->activationGrantRepository->replace($activationGrant, $claimed)) {
                throw new StateConflict(
                    'The activation delivery generation changed concurrently.'
                );
            }

            return $claimed;
        });

        return $claimed;
    }

    /**
     * Invokes the provider with no transaction open
     */
    private function invoke(
        ActivationGrant $claimed,
        CredentialDeliveryClaimToken $claimToken
    ): CredentialDeliveryAttemptResult {
        $delivery = $claimed->getDelivery();
        $at = $this->clock->now();
        try {
            $material = $delivery->materialForClaim($claimToken, $at);
        } catch (CredentialDeliveryTransitionException $credentialDeliveryTransitionException) {
            throw new StateConflict(
                'The activation delivery claim is stale.',
                previous: $credentialDeliveryTransitionException
            );
        }

        try {
            $credential = $this->invitationDeliveryCipher->decrypt($material);

            $outcome = $this->credentialDeliveryProvider->deliver(new CredentialDeliveryInvocation(
                $claimed->purpose(),
                $delivery->getId()->toString(),
                $delivery->getEmail(),
                $credential,
                $claimed->getOrigin()
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
        DeliverUserInvitation $command,
        ActivationGrant $claimed,
        CredentialDeliveryClaimToken $claimToken,
        CredentialDeliveryAttemptResult $attemptResult,
        MessageId $messageId
    ): ?UserInvitationDelivered {
        /** @var ?UserInvitationDelivered $successEvent */
        $successEvent = $this->unitOfWork->commitTransactional(function () use (
            $command,
            $claimed,
            $claimToken,
            $attemptResult,
            $messageId
        ): ?UserInvitationDelivered {
            $current = $this->activationGrantRepository->getByDeliveryId(
                $command->getActivationDeliveryId()
            );
            $latest = $this->activationGrantRepository->getLatestByUserId($command->getUserId());
            if (
                !$this->isCurrent($current, $latest, $command)
                || $current->getRevision() !== $claimed->getRevision()
            ) {
                throw new StateConflict(
                    'The activation delivery outcome is stale.'
                );
            }

            if (!$current->getDelivery()->sameStateAs($claimed->getDelivery())) {
                throw new LogicException('The credential repository changed claim state without a revision.');
            }

            $occurredAt = $this->clock->now();
            $outcome = $attemptResult->getOutcome();
            try {
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
            } catch (CredentialDeliveryTransitionException $credentialDeliveryTransitionException) {
                throw new StateConflict(
                    'The activation delivery outcome is stale.',
                    previous: $credentialDeliveryTransitionException
                );
            }

            if (!$this->activationGrantRepository->replace($current, $replacement)) {
                throw new StateConflict(
                    'The activation delivery outcome changed concurrently.'
                );
            }

            $action = AuditAction::INVITATION_DELIVERY_FAILED;
            if ($outcome === CredentialDeliveryOutcome::DELIVERED) {
                $action = AuditAction::INVITATION_DELIVERY_CONFIRMED;
            }

            $this->auditEvidenceRepository->add(AuditEvidence::record(
                $command->getActorId(),
                new AuditSubject($command->getUserId()),
                $action,
                $occurredAt,
                new AuditGrantOutcome(
                    AuditGrantState::fromGrant($current),
                    AuditGrantState::fromGrant($replacement),
                    $replacement->getDelivery()->getStatus(),
                    $attemptResult->getFailure()
                ),
                new AuditCorrelation($messageId, null)
            ));

            if ($outcome !== CredentialDeliveryOutcome::DELIVERED) {
                return null;
            }

            return new UserInvitationDelivered(
                $command->getActorId(),
                $command->getUserId(),
                $command->getActivationDeliveryId()
            );
        });

        return $successEvent;
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
     *
     * @phpstan-assert-if-true ActivationGrant $activationGrant
     * @phpstan-assert-if-true ActivationGrant $latest
     */
    private function isCurrent(
        ?ActivationGrant $activationGrant,
        ?ActivationGrant $latest,
        DeliverUserInvitation $command
    ): bool {
        if (
            $activationGrant instanceof ActivationGrant
            && (!$activationGrant->getDelivery()->getId()->equals($command->getActivationDeliveryId())
                || $activationGrant->purpose() !== 'activation'
                || !$activationGrant->ownsDelivery(
                    $command->getActivationDeliveryId(),
                    $activationGrant->getUserId()
                ))
        ) {
            throw new LogicException('The credential repository returned another delivery.');
        }

        if ($latest instanceof ActivationGrant && !$latest->getUserId()->equals($command->getUserId())) {
            throw new LogicException("The credential repository returned another User's generation.");
        }

        return $activationGrant instanceof ActivationGrant
            && $latest instanceof ActivationGrant
            && $latest->getId()->equals($activationGrant->getId())
            && $latest->getRevision() === $activationGrant->getRevision()
            && $activationGrant->getUserId()->equals($command->getUserId())
            && $activationGrant->isIssued();
    }
}
