<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PasswordChangeRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\TransactionalUnitOfWork;
use SensitiveParameter;
use Throwable;

/**
 * Class PasswordManagement
 *
 * Coordinates verified password changes and single-use reset redemption atomically.
 */
final readonly class PasswordManagement
{
    /**
     * Constructs PasswordManagement
     */
    public function __construct(
        private UserRepository $userRepository,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private SessionRevocationService $sessionRevocationService,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private TransactionalUnitOfWork $unitOfWork,
        private Clock $clock,
        private PasswordHasher $passwordHasher,
        private PasswordValidator $passwordValidator,
        private PasswordHash $dummyPasswordHash,
        private EventDispatcher $eventDispatcher
    ) {
    }

    /**
     * Verifies the authenticated owner before changing their established password
     */
    public function changePassword(
        UserId $authenticatedUserId,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword
    ): void {
        try {
            $changedAt = $this->unitOfWork->commitTransactional(function () use (
                $authenticatedUserId,
                $currentPassword,
                $newPassword
            ): DateTimeImmutable {
                $user = $this->userRepository->getById($authenticatedUserId);
                $passwordHash = $user?->getPasswordHash();
                $isActiveUser = $user instanceof User
                    && $user->getId()->equals($authenticatedUserId)
                    && $user->getState() === UserState::ACTIVE
                    && $passwordHash instanceof PasswordHash;
                $verificationHash = $this->dummyPasswordHash;
                if ($isActiveUser) {
                    $verificationHash = $passwordHash;
                }

                $credentialsMatch = $this->passwordValidator->validate(
                    $currentPassword,
                    $verificationHash->toString()
                );
                if (!$isActiveUser || !$credentialsMatch) {
                    throw new PasswordChangeRejectedException('Password change rejected.');
                }

                $changedAt = $this->clock->now();
                $replacementUser = clone $user;
                $replacementUser->changePassword(
                    PasswordHash::fromString($this->passwordHasher->hash($newPassword)),
                    $changedAt
                );
                if (!$this->userRepository->replaceAuthenticationAuthority($user, $replacementUser)) {
                    throw new PasswordChangeRejectedException('Password change rejected.');
                }

                new PasswordResetRevocationService($this->passwordResetGrantRepository)->revokeFor(
                    $authenticatedUserId,
                    $changedAt
                );
                $this->sessionRevocationService->revokeAllActiveFor($authenticatedUserId, $changedAt);

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $authenticatedUserId->toString(),
                    'user.password_changed',
                    $authenticatedUserId
                ));

                return $changedAt;
            });

            $this->eventDispatcher->trigger(new PasswordChanged($authenticatedUserId, $changedAt));
        } catch (Throwable $throwable) {
            $this->publishFailure(
                'changePassword',
                ['user_id' => $authenticatedUserId->toString()],
                'Password change failed.'
            );
            throw $throwable;
        }
    }

    /**
     * Uses a password-reset credential and invalidates all prior authentication authority
     */
    public function resetPassword(
        UserId $userId,
        #[SensitiveParameter] string $resetCredential,
        #[SensitiveParameter] string $plainPassword
    ): void {
        try {
            $completedAt = $this->unitOfWork->commitTransactional(function () use (
                $userId,
                $resetCredential,
                $plainPassword
            ): DateTimeImmutable {
                $completedAt = $this->clock->now();
                $user = $this->userRepository->getById($userId);
                if (!$user instanceof User || !$this->userRepository->validateRecoveryAuthority($user)) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                $passwordResetGrant = $this->passwordResetGrantRepository->getLatestByUserId($userId);
                try {
                    $credential = PasswordResetCredential::fromString($resetCredential);
                } catch (Throwable) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                if (
                    !$user->getId()->equals($userId)
                    || $user->getState() !== UserState::ACTIVE
                    || !$user->getPasswordHash() instanceof PasswordHash
                    || !$passwordResetGrant instanceof PasswordResetGrant
                    || !$passwordResetGrant->getUserId()->equals($userId)
                    || $passwordResetGrant->purpose() !== 'password_reset'
                    || !$passwordResetGrant->isAuthorizedBy($user)
                    || !$passwordResetGrant->isUsableAt($completedAt)
                    || !$passwordResetGrant->matchesCredential($credential)
                ) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                $passwordHash = PasswordHash::fromString($this->passwordHasher->hash($plainPassword));
                $replacementUser = clone $user;
                $replacementUser->resetPassword($passwordHash, $completedAt);
                if (
                    !$this->passwordResetGrantRepository->replace(
                        $passwordResetGrant,
                        $passwordResetGrant->consume($completedAt)
                    )
                ) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                if (!$this->userRepository->replaceAuthenticationAuthority($user, $replacementUser)) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                $this->sessionRevocationService->revokeAllActiveFor($userId, $completedAt);

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $userId->toString(),
                    'user.password_reset_completed',
                    $userId
                ));

                return $completedAt;
            });

            $this->eventDispatcher->trigger(new PasswordResetCompleted($userId, $completedAt));
        } catch (Throwable $throwable) {
            $this->publishFailure('resetPassword', ['user_id' => $userId->toString()], 'Password reset failed.');
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
