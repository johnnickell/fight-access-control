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
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
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
        private EventDispatcher $eventDispatcher,
        private UserRepository $userRepository
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
            $successEvent = $this->recordOutcome(
                $command,
                $claimed,
                $claimToken,
                $attemptResult,
                $commandMessage->id()
            );
            if ($successEvent instanceof PasswordResetDeliveryConfirmed) {
                $this->eventDispatcher->trigger($successEvent);
            }
        } catch (Throwable $throwable) {
            try {
                $this->eventDispatcher->trigger(new CommandFailedEvent($command, 'Password reset delivery failed.'));
            } catch (Throwable) {
                // Notification failure must not replace the original operation failure.
            }

            throw $throwable;
        }
    }

    /**
     * Acquires one exact delivery claim before external invocation
     */
    private function claim(DeliverPasswordReset $command): PasswordResetGrant
    {
        /** @var PasswordResetGrant $claimed */
        $claimed = $this->unitOfWork->commitTransactional(function () use ($command): PasswordResetGrant {
            $user = $this->userRepository->getById($command->getUserId());
            if ($user instanceof User && !$user->getId()->equals($command->getUserId())) {
                throw new LogicException('The User repository returned another identity.');
            }

            if (!$user instanceof User || !$this->userRepository->validateRecoveryAuthority($user)) {
                throw new StateConflict('The password-reset delivery work is no longer retryable.');
            }

            $grant = $this->passwordResetGrantRepository->getByDeliveryId(
                $command->getPasswordResetDeliveryId()
            );
            $latest = $this->passwordResetGrantRepository->getLatestByUserId($command->getUserId());
            if (!$this->isCurrent($grant, $latest, $command) || !$grant->isAuthorizedBy($user)) {
                throw new StateConflict(
                    'The password-reset delivery work is no longer retryable.'
                );
            }

            $claimedAt = $this->clock->now();
            $leaseUntil = $this->leaseUntil($claimedAt, $grant->getExpiresAt());
            try {
                $claimed = $grant->claimDelivery(
                    CredentialDeliveryClaimToken::generate(),
                    $claimedAt,
                    $leaseUntil
                );
            } catch (CredentialDeliveryTransitionException $credentialDeliveryTransitionException) {
                throw new StateConflict(
                    'The password-reset delivery cannot be claimed.',
                    previous: $credentialDeliveryTransitionException
                );
            }

            if (!$this->passwordResetGrantRepository->replace($grant, $claimed)) {
                throw new StateConflict(
                    'The password-reset delivery generation changed concurrently.'
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
        PasswordResetGrant $claimed,
        CredentialDeliveryClaimToken $claimToken
    ): CredentialDeliveryAttemptResult {
        $delivery = $claimed->getDelivery();
        $at = $this->clock->now();
        try {
            $material = $delivery->materialForClaim($claimToken, $at);
        } catch (CredentialDeliveryTransitionException $credentialDeliveryTransitionException) {
            throw new StateConflict(
                'The password-reset delivery claim is stale.',
                previous: $credentialDeliveryTransitionException
            );
        }

        try {
            $credential = $this->passwordResetDeliveryCipher->decrypt($material);
            $outcome = $this->credentialDeliveryProvider->deliver(new CredentialDeliveryInvocation(
                $claimed->purpose(),
                $delivery->getId()->toString(),
                $delivery->getEmail(),
                $credential,
                null
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
        CredentialDeliveryAttemptResult $attemptResult,
        MessageId $messageId
    ): ?PasswordResetDeliveryConfirmed {
        /** @var ?PasswordResetDeliveryConfirmed $successEvent */
        $successEvent = $this->unitOfWork->commitTransactional(function () use (
            $command,
            $claimed,
            $claimToken,
            $attemptResult,
            $messageId
        ): ?PasswordResetDeliveryConfirmed {
            $user = $this->userRepository->getById($command->getUserId());
            if ($user instanceof User && !$user->getId()->equals($command->getUserId())) {
                throw new LogicException('The User repository returned another identity.');
            }

            if (!$user instanceof User || !$this->userRepository->validateRecoveryAuthority($user)) {
                throw new StateConflict('The password-reset delivery outcome is stale.');
            }

            $current = $this->passwordResetGrantRepository->getByDeliveryId(
                $command->getPasswordResetDeliveryId()
            );
            $latest = $this->passwordResetGrantRepository->getLatestByUserId($command->getUserId());
            if (
                !$this->isCurrent($current, $latest, $command)
                || !$current->isAuthorizedBy($user)
                || $current->getRevision() !== $claimed->getRevision()
            ) {
                throw new StateConflict(
                    'The password-reset delivery outcome is stale.'
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
                    'The password-reset delivery outcome is stale.',
                    previous: $credentialDeliveryTransitionException
                );
            }

            if (!$this->passwordResetGrantRepository->replace($current, $replacement)) {
                throw new StateConflict(
                    'The password-reset delivery outcome changed concurrently.'
                );
            }

            $action = AuditAction::PASSWORD_RESET_DELIVERY_FAILED;
            if ($outcome === CredentialDeliveryOutcome::DELIVERED) {
                $action = AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED;
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

            return new PasswordResetDeliveryConfirmed(
                $command->getActorId(),
                $command->getUserId(),
                $command->getPasswordResetDeliveryId(),
                $occurredAt
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
     * @phpstan-assert-if-true PasswordResetGrant $grant
     * @phpstan-assert-if-true PasswordResetGrant $latest
     */
    private function isCurrent(
        ?PasswordResetGrant $grant,
        ?PasswordResetGrant $latest,
        DeliverPasswordReset $command
    ): bool {
        if (
            $grant instanceof PasswordResetGrant
            && (!$grant->getDelivery()->getId()->equals($command->getPasswordResetDeliveryId())
                || $grant->purpose() !== 'password_reset'
                || !$grant->ownsDelivery($command->getPasswordResetDeliveryId(), $grant->getUserId()))
        ) {
            throw new LogicException('The credential repository returned another delivery.');
        }

        if ($latest instanceof PasswordResetGrant && !$latest->getUserId()->equals($command->getUserId())) {
            throw new LogicException("The credential repository returned another User's generation.");
        }

        return $grant instanceof PasswordResetGrant
            && $latest instanceof PasswordResetGrant
            && $latest->getId()->equals($grant->getId())
            && $latest->getRevision() === $grant->getRevision()
            && $grant->getUserId()->equals($command->getUserId())
            && $grant->isIssued();
    }
}
