<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeConfirmationRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use SensitiveParameter;
use Throwable;

/**
 * Class EmailChangeConfirmation
 *
 * Coordinates single-use email confirmation and its authority invalidation in one transaction.
 */
final readonly class EmailChangeConfirmation
{
    /**
     * Constructs EmailChangeConfirmation
     */
    public function __construct(
        private UserRepository $userRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private SessionRevocationService $sessionRevocationService,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private Clock $clock,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * Completes a current email-change credential without issuing new authentication tokens
     */
    public function confirmEmail(
        UserId $userId,
        #[SensitiveParameter] string $emailChangeCredential
    ): void {
        try {
            $confirmedAt = $this->unitOfWork->commitTransactional(function () use (
                $userId,
                $emailChangeCredential
            ): DateTimeImmutable {
                try {
                    $confirmedAt = $this->clock->now();
                    $user = $this->userRepository->getById($userId);
                    if (!$user instanceof User || !$this->userRepository->validateRecoveryAuthority($user)) {
                        throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                    }

                    $grant = $this->emailChangeGrantRepository->getLatestByUserId($userId);
                    $credential = EmailChangeCredential::fromString($emailChangeCredential);
                    $pendingEmail = $user->getPendingEmailChange();

                    if (
                        !$user->getId()->equals($userId)
                        || $user->getState() !== UserState::ACTIVE
                        || !$pendingEmail instanceof EmailAddress
                        || !$grant instanceof EmailChangeGrant
                        || !$grant->getUserId()->equals($userId)
                        || $grant->purpose() !== 'email_change'
                        || !$grant->isUsableAt($confirmedAt)
                        || !$grant->matchesCredential($credential)
                        || $grant->getDelivery()->getEmail()->canonical() !== $pendingEmail->canonical()
                    ) {
                        throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                    }

                    $replacementUser = clone $user;
                    $replacementUser->confirmEmailChange($confirmedAt);
                    if (!$this->emailChangeGrantRepository->replace($grant, $grant->consume($confirmedAt))) {
                        throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                    }

                    if (!$this->userRepository->replaceEmailChangeConfirmation($user, $replacementUser)) {
                        throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                    }

                    new PasswordResetRevocationService($this->passwordResetGrantRepository)->revokeFor(
                        $userId,
                        $confirmedAt
                    );
                    $this->sessionRevocationService->revokeAllActiveFor($userId, $confirmedAt);

                    $this->auditEvidenceRepository->add(AuditEvidence::record(
                        $userId->toString(),
                        'user.email_change_confirmed',
                        $userId
                    ));

                    return $confirmedAt;
                } catch (Throwable $throwable) {
                    if ($throwable instanceof EmailChangeConfirmationRejectedException) {
                        throw $throwable;
                    }

                    throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                }
            });

            $this->eventDispatcher->trigger(new EmailChangeConfirmed($userId, $confirmedAt));
        } catch (Throwable $throwable) {
            $this->publishFailure(
                'confirmEmail',
                ['user_id' => $userId->toString()],
                'Email change confirmation failed.'
            );
            throw $throwable;
        }
    }

    /**
     * Dispatches only allowlisted operation context after a sensitive failure
     *
     * @param string               $operation
     * @param array<string, mixed> $safeData
     * @param string               $failureMessage
     */
    private function publishFailure(string $operation, array $safeData, string $failureMessage): void
    {
        try {
            $this->eventDispatcher->trigger(new RedactedCommandFailed(
                self::class.'::'.$operation,
                $safeData,
                $failureMessage
            ));
        } catch (Throwable) {
            // Notification failure must not replace the original operation failure.
        }
    }
}
