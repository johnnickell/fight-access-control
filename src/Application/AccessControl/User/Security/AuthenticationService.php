<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Service\AuthenticationClock;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeConfirmationRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\EmailChangeConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordChanged;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetCompleted;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\LoginRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PasswordChangeRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use Fight\Common\Application\Messaging\Event\EventDispatcher;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use LogicException;
use SensitiveParameter;
use Throwable;

/**
 * Coordinates the supported synchronous JWT and refresh-session authentication lifecycle.
 */
final readonly class AuthenticationService
{
    private const int REVOCATION_RETRY_LIMIT = 3;

    /**
     * Creates the authentication service.
     */
    public function __construct(
        private UserRepository $userRepository,
        private ActivationGrantRepository $activationGrantRepository,
        private RefreshSessionRepository $refreshSessionRepository,
        private UnitOfWork $unitOfWork,
        private AuthenticationClock $clock,
        private LoginThrottle $loginThrottle,
        private RefreshCredentialGenerator $refreshCredentialGenerator,
        private PasswordHasher $passwordHasher,
        private PasswordValidator $passwordValidator,
        private TokenEncoder $tokenEncoder,
        private AuthenticationTokenPolicy $tokenPolicy,
        private PasswordHash $dummyPasswordHash,
        private EventDispatcher $eventDispatcher,
        private PasswordResetGrantRepository $passwordResetGrantRepository,
        private AuditEvidenceRepository $auditEvidenceRepository,
        private EmailChangeGrantRepository $emailChangeGrantRepository
    ) {
    }

    /**
     * Confirms a current email-change credential without issuing new authentication tokens.
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
                    $grant = $this->emailChangeGrantRepository->getLatestByUserId($userId);
                    $credential = EmailChangeCredential::fromString($emailChangeCredential);
                    $pendingEmail = $user?->getPendingEmailChange();

                    if (
                        !$user instanceof User
                        || !$user->getId()->equals($userId)
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
                    $replacementUser->confirmEmailChange();
                    $replacementUser->advanceAuthenticationAuthorityRevision();
                    if (!$this->emailChangeGrantRepository->replace($grant, $grant->consume($confirmedAt))) {
                        throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                    }

                    if (!$this->userRepository->replaceEmailChangeConfirmation($user, $replacementUser)) {
                        throw new EmailChangeConfirmationRejectedException('Email change confirmation rejected.');
                    }

                    foreach ($this->refreshSessionRepository->getAllActiveByUserId($userId, $confirmedAt) as $session) {
                        $this->revokeSession($session);
                    }

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
            $this->publishFailure('confirmEmail', ['user_id' => $userId->toString()], $throwable);
            throw $throwable;
        }
    }

    /**
     * Verifies the authenticated owner before changing their established password.
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
                    PasswordHash::fromString($this->passwordHasher->hash($newPassword))
                );
                $replacementUser->advanceAuthenticationAuthorityRevision();
                if (!$this->userRepository->replaceAuthenticationAuthority($user, $replacementUser)) {
                    throw new PasswordChangeRejectedException('Password change rejected.');
                }

                foreach (
                    $this->refreshSessionRepository->getAllActiveByUserId($authenticatedUserId, $changedAt) as $session
                ) {
                    $this->revokeSession($session);
                }

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
                $throwable
            );
            throw $throwable;
        }
    }

    /**
     * Redeems a password-reset credential and invalidates all prior authentication authority.
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
                $passwordResetGrant = $this->passwordResetGrantRepository->getLatestByUserId($userId);
                try {
                    $credential = PasswordResetCredential::fromString($resetCredential);
                } catch (Throwable) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                if (
                    !$user instanceof User
                    || !$user->getId()->equals($userId)
                    || $user->getState() !== UserState::ACTIVE
                    || !$user->getPasswordHash() instanceof PasswordHash
                    || !$passwordResetGrant instanceof PasswordResetGrant
                    || !$passwordResetGrant->getUserId()->equals($userId)
                    || $passwordResetGrant->purpose() !== 'password_reset'
                    || !$passwordResetGrant->isUsableAt($completedAt)
                    || !$passwordResetGrant->matchesCredential($credential)
                ) {
                    throw new PasswordResetRejectedException('Password reset rejected.');
                }

                $passwordHash = PasswordHash::fromString($this->passwordHasher->hash($plainPassword));
                $replacementUser = clone $user;
                $replacementUser->resetPassword($passwordHash);
                $replacementUser->advanceAuthenticationAuthorityRevision();
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

                foreach ($this->refreshSessionRepository->getAllActiveByUserId($userId, $completedAt) as $session) {
                    $this->revokeSession($session);
                }

                $this->auditEvidenceRepository->add(AuditEvidence::record(
                    $userId->toString(),
                    'user.password_reset_completed',
                    $userId
                ));

                return $completedAt;
            });

            $this->eventDispatcher->trigger(new PasswordResetCompleted($userId, $completedAt));
        } catch (Throwable $throwable) {
            $this->publishFailure('resetPassword', ['user_id' => $userId->toString()], $throwable);
            throw $throwable;
        }
    }

    /**
     * Activates an invited identity and returns its first token set.
     */
    public function activate(
        UserId $userId,
        #[SensitiveParameter] string $activationCredential,
        #[SensitiveParameter] string $plainPassword,
        bool $remember = false
    ): TokenSet {
        try {
            /** @var array{TokenSet, DateTimeImmutable} $activation */
            $activation = $this->unitOfWork->commitTransactional(function () use (
                $userId,
                $activationCredential,
                $plainPassword,
                $remember
            ): array {
                $authenticatedAt = $this->clock->now();
                $user = $this->userRepository->getById($userId);
                $grant = $this->activationGrantRepository->getLatestByUserId($userId);
                $credential = ActivationCredential::fromString($activationCredential);

                if (
                    !$user instanceof User
                    || !$user->getId()->equals($userId)
                    || !$grant instanceof ActivationGrant
                    || !$grant->getUserId()->equals($userId)
                    || $grant->purpose() !== 'activation'
                    || !$grant->isUsableAt($authenticatedAt)
                    || !$grant->matchesCredential($credential)
                ) {
                    throw new LogicException('The activation grant cannot activate this invited account.');
                }

                $passwordHash = PasswordHash::fromString($this->passwordHasher->hash($plainPassword));
                $replacementUser = clone $user;
                $replacementUser->activate($passwordHash);
                $replacementUser->advanceAuthenticationAuthorityRevision();

                $refreshCredential = $this->refreshCredentialGenerator->generate();
                $refreshSession = $this->newRefreshSession(
                    $replacementUser,
                    $refreshCredential,
                    $authenticatedAt,
                    $remember
                );
                $tokenSet = $this->tokenSet(
                    $replacementUser,
                    $refreshSession,
                    $refreshCredential,
                    $authenticatedAt
                );
                $consumedGrant = $grant->consume($authenticatedAt);
                if (!$this->activationGrantRepository->replace($grant, $consumedGrant)) {
                    throw new LogicException('The activation grant changed concurrently.');
                }

                if (
                    !$this->userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                        $user,
                        $replacementUser,
                        $refreshSession
                    )
                ) {
                    throw new LogicException('The invited account changed concurrently.');
                }

                return [$tokenSet, $authenticatedAt];
            });

            $this->eventDispatcher->trigger(new UserActivated(
                $userId,
                $activation[0]->getRefreshSessionId(),
                $activation[1]
            ));

            return $activation[0];
        } catch (Throwable $throwable) {
            $this->publishFailure('activate', ['user_id' => $userId->toString()], $throwable);
            throw $throwable;
        }
    }

    /**
     * Verifies an active identity and returns a new token set.
     */
    public function login(
        string $email,
        #[SensitiveParameter] string $plainPassword,
        bool $remember = false
    ): TokenSet {
        try {
            /** @var array{TokenSet, DateTimeImmutable} $login */
            $login = $this->unitOfWork->commitTransactional(function () use (
                $email,
                $plainPassword,
                $remember
            ): array {
                $emailAddress = EmailAddress::fromString($email);
                $user = $this->userRepository->getByEmail($emailAddress);
                $passwordHash = $user?->getPasswordHash();
                $isActiveUser = $user instanceof User
                    && $user->getState() === UserState::ACTIVE
                    && $passwordHash instanceof PasswordHash;
                $loginIsAllowed = $this->loginThrottle->allows($emailAddress);
                $verificationHash = $this->dummyPasswordHash;
                if ($loginIsAllowed && $isActiveUser) {
                    $verificationHash = $passwordHash;
                }

                $credentialsMatch = $this->passwordValidator->validate(
                    $plainPassword,
                    $verificationHash->toString()
                );

                if (!$isActiveUser || !$credentialsMatch || !$loginIsAllowed) {
                    throw new LoginRejectedException('Login rejected.');
                }

                $replacementUser = clone $user;
                if ($this->passwordValidator->needsRehash($passwordHash->toString())) {
                    $replacementUser->rehashPassword(
                        PasswordHash::fromString($this->passwordHasher->hash($plainPassword))
                    );
                }

                $replacementUser->advanceAuthenticationAuthorityRevision();

                $authenticatedAt = $this->clock->now();
                $refreshCredential = $this->refreshCredentialGenerator->generate();
                $refreshSession = $this->newRefreshSession(
                    $replacementUser,
                    $refreshCredential,
                    $authenticatedAt,
                    $remember
                );
                $tokenSet = $this->tokenSet(
                    $replacementUser,
                    $refreshSession,
                    $refreshCredential,
                    $authenticatedAt
                );
                if (
                    !$this->userRepository->replaceAuthenticationAuthorityAndAddRefreshSession(
                        $user,
                        $replacementUser,
                        $refreshSession
                    )
                ) {
                    throw new LoginRejectedException('Login rejected.');
                }

                return [$tokenSet, $authenticatedAt];
            });

            $this->eventDispatcher->trigger(new UserLoggedIn(
                $login[0]->getUserId(),
                $login[0]->getRefreshSessionId(),
                $login[1]
            ));

            return $login[0];
        } catch (Throwable $throwable) {
            $this->publishFailure('login', [], $throwable);
            throw $throwable;
        }
    }

    /**
     * Revalidates a refresh credential and returns a rotation or bounded-conflict outcome.
     */
    public function refresh(#[SensitiveParameter] string $refreshCredential): RefreshResult
    {
        try {
            /** @var RefreshResult|RefreshSessionNotFoundException $refreshResult */
            $refreshResult = $this->unitOfWork->commitTransactional(function () use (
                $refreshCredential
            ): RefreshResult|RefreshSessionNotFoundException {
                $authenticatedAt = $this->clock->now();
                $credential = RefreshCredential::fromString($refreshCredential);
                $refreshSession = $this->refreshSessionRepository->getByCredential($credential);
                if (!$refreshSession instanceof RefreshSession) {
                    $refreshSession = $this->refreshSessionRepository->getByUsedCredential($credential);
                    $user = null;
                    if ($refreshSession instanceof RefreshSession) {
                        $user = $this->userRepository->getById($refreshSession->getUserId());
                    }

                    return $this->resolveUsedCredential($refreshSession, $user, $credential, $authenticatedAt);
                }

                $user = $this->userRepository->getById($refreshSession->getUserId());

                $this->assertAuthoritativeSession($refreshSession, $user, $authenticatedAt);
                $rotatedCredential = $this->refreshCredentialGenerator->generate();
                $proposedIdleExpiry = $this->tokenPolicy->refreshIdleExpiresAt(
                    $authenticatedAt,
                    $refreshSession->isRemembered()
                );
                $absoluteExpiry = $refreshSession->getAbsoluteExpiresAt();
                $idleExpiry = $proposedIdleExpiry < $absoluteExpiry ? $proposedIdleExpiry : $absoluteExpiry;
                $rotatedSession = $refreshSession->rotate($rotatedCredential, $authenticatedAt, $idleExpiry);
                if (!$this->refreshSessionRepository->replace($refreshSession, $rotatedSession)) {
                    $observedAt = $this->clock->now();
                    $currentSession = $this->refreshSessionRepository->getById($refreshSession->getId());

                    return $this->resolveUsedCredential($currentSession, $user, $credential, $observedAt);
                }

                return RefreshResult::rotated(
                    $this->tokenSet($user, $rotatedSession, $rotatedCredential, $authenticatedAt)
                );
            });

            if ($refreshResult instanceof RefreshSessionNotFoundException) {
                throw $refreshResult;
            }

            return $refreshResult;
        } catch (Throwable $throwable) {
            $this->publishFailure('refresh', [], $throwable);
            throw $throwable;
        }
    }

    /**
     * Revokes only the session selected by the presented refresh credential.
     */
    public function logout(#[SensitiveParameter] string $refreshCredential): void
    {
        try {
            $refreshSessionId = $this->unitOfWork->commitTransactional(function () use (
                $refreshCredential
            ): RefreshSessionId {
                $credential = RefreshCredential::fromString($refreshCredential);
                $refreshSession = $this->refreshSessionRepository->getByCredential($credential);
                if (!$refreshSession instanceof RefreshSession) {
                    throw new RefreshSessionNotFoundException('The refresh session does not exist.');
                }

                $refreshSession = $this->revokeSession($refreshSession);

                return $refreshSession->getId();
            });

            $this->eventDispatcher->trigger(new CurrentSessionLoggedOut($refreshSessionId));
        } catch (Throwable $throwable) {
            $this->publishFailure('logout', [], $throwable);
            throw $throwable;
        }
    }

    /**
     * Creates one authoritative refresh session using configured lifetime policy.
     */
    private function newRefreshSession(
        User $user,
        RefreshCredential $refreshCredential,
        DateTimeImmutable $authenticatedAt,
        bool $remember
    ): RefreshSession {
        return RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            $refreshCredential,
            $authenticatedAt,
            $this->tokenPolicy->refreshIdleExpiresAt($authenticatedAt, $remember),
            $this->tokenPolicy->refreshAbsoluteExpiresAt($authenticatedAt, $remember),
            $user->getAuthenticationVersion(),
            $remember
        );
    }

    /**
     * Creates safe access and refresh material for one authoritative session.
     */
    private function tokenSet(
        User $user,
        RefreshSession $refreshSession,
        RefreshCredential $refreshCredential,
        DateTimeImmutable $authenticatedAt
    ): TokenSet {
        $accessTokenExpiresAt = $this->tokenPolicy->accessExpiresAt($authenticatedAt);
        $accessToken = $this->tokenEncoder->encode([
            'auth_version' => $user->getAuthenticationVersion(),
            'iat'          => $authenticatedAt->getTimestamp(),
            'sid'          => $refreshSession->getId()->toString(),
            'sub'          => $user->getId()->toString(),
            'type'         => 'access',
        ], $accessTokenExpiresAt);

        return new TokenSet(
            $user->getId(),
            $refreshSession->getId(),
            $refreshCredential,
            $refreshSession->getAbsoluteExpiresAt(),
            $refreshSession->isRemembered(),
            AccessToken::fromString($accessToken),
            $accessTokenExpiresAt
        );
    }

    /**
     * Rejects any session or owner that is no longer authoritative.
     */
    private function assertAuthoritativeSession(
        ?RefreshSession $refreshSession,
        ?User $user,
        DateTimeImmutable $authenticatedAt
    ): void {
        if (
            !$refreshSession instanceof RefreshSession
            || !$user instanceof User
            || !$user->getId()->equals($refreshSession->getUserId())
            || $user->getState() !== UserState::ACTIVE
            || $user->getAuthenticationVersion() !== $refreshSession->getAuthenticationVersion()
            || !$refreshSession->isUsableAt($authenticatedAt)
        ) {
            throw new RefreshSessionNotFoundException('The refresh session is not authoritative.');
        }
    }

    /**
     * Resolves a previously authoritative credential as a benign conflict or terminal replay.
     */
    private function resolveUsedCredential(
        ?RefreshSession $refreshSession,
        ?User $user,
        RefreshCredential $credential,
        DateTimeImmutable $observedAt
    ): RefreshResult|RefreshSessionNotFoundException {
        $this->assertAuthoritativeSession($refreshSession, $user, $observedAt);
        if (
            $refreshSession->matchesMostRecentlyUsedCredentialWithin(
                $credential,
                $observedAt,
                $this->tokenPolicy->refreshConflictWindow()
            )
        ) {
            return RefreshResult::conflict();
        }

        return $this->revokeCompromisedSession($refreshSession);
    }

    /**
     * Atomically revokes the authoritative session family after terminal credential replay.
     */
    private function revokeCompromisedSession(RefreshSession $refreshSession): RefreshSessionNotFoundException
    {
        $this->revokeSession($refreshSession);

        return new RefreshSessionNotFoundException('The refresh session is not authoritative.');
    }

    /**
     * Replaces the latest authoritative session state with an immutable revocation.
     */
    private function revokeSession(RefreshSession $refreshSession): RefreshSession
    {
        $attempts = 0;
        while (!$refreshSession->isRevoked() && $attempts < self::REVOCATION_RETRY_LIMIT) {
            ++$attempts;
            $revokedSession = $refreshSession->revoke();
            if ($this->refreshSessionRepository->replace($refreshSession, $revokedSession)) {
                return $revokedSession;
            }

            $refreshSession = $this->refreshSessionRepository->getById($refreshSession->getId());
            if (!$refreshSession instanceof RefreshSession) {
                throw new RefreshSessionNotFoundException('The refresh session is not authoritative.');
            }
        }

        if ($refreshSession->isRevoked()) {
            return $refreshSession;
        }

        throw new RefreshSessionNotFoundException('The refresh session is not authoritative.');
    }

    /**
     * Publishes only allowlisted operation context after a sensitive failure.
     *
     * @param string               $operation
     * @param array<string, mixed> $safeData
     * @param Throwable            $throwable
     */
    private function publishFailure(string $operation, array $safeData, Throwable $throwable): void
    {
        $this->eventDispatcher->trigger(new RedactedCommandFailed(
            self::class.'::'.$operation,
            $safeData,
            $throwable->getMessage()
        ));
    }
}
