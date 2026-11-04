<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Authentication;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrantRepository;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\TestPasswordSecurity;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;

trait SynchronousAuthenticationFixture
{
    private const string ACTIVATION_CREDENTIAL = 'activate-once';

    private const string REFRESH_CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const string RESET_CREDENTIAL = 'reset-once';

    private const string EMAIL_CHANGE_CREDENTIAL = 'confirm-email-once';

    /**
     * @return array<string, array{?User, bool}>
     */
    public static function rejectedLoginStates(): array
    {
        $activeUser = static function (string $email): User {
            $user = User::invite(
                UserId::generate(),
                EmailAddress::fromString($email),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );
            $user->activate(
                PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );

            return $user;
        };

        return [
            'unknown identity'          => [null, true],
            'pending identity'          => [
                User::invite(
                    UserId::generate(),
                    EmailAddress::fromString('pending@example.test'),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                ),
                true
            ],
            'disabled identity'         => [UserFixture::withState('disabled@example.test', UserState::DISABLED), true],
            'deleted identity'          => [UserFixture::withState('deleted@example.test', UserState::DELETED), true],
            'throttled active identity' => [$activeUser('throttled@example.test'), false],
            'invalid active password'   => [$activeUser('invalid-password@example.test'), true]
        ];
    }

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function refreshLifetimeCases(): array
    {
        return [
            'ordinary session'   => [false, '2026-08-20T12:00:00+00:00', '2026-08-21T11:00:00+00:00'],
            'remembered session' => [true, '2026-09-03T12:00:00+00:00', '2026-09-18T11:00:00+00:00']
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedPasswordResetAuthority(): array
    {
        return [
            'wrong credential'        => ['wrong credential', 'wrong-reset-credential'],
            'empty credential'        => ['empty credential', ''],
            'expiry boundary'         => ['expiry boundary', self::RESET_CREDENTIAL],
            'consumed grant replay'   => ['consumed grant replay', self::RESET_CREDENTIAL],
            'revoked grant replay'    => ['revoked grant replay', self::RESET_CREDENTIAL],
            'missing user authority'  => ['missing user authority', self::RESET_CREDENTIAL],
            'missing grant authority' => ['missing grant authority', self::RESET_CREDENTIAL]
        ];
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function nonAuthoritativePasswordResetDeliveryCases(): array
    {
        return [
            'absent delivery'              => [false],
            'already invalidated delivery' => [true]
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedEmailConfirmationAuthority(): array
    {
        return [
            'wrong credential'       => ['wrong', 'wrong-confirmation'],
            'malformed credential'   => ['malformed', ''],
            'expiry boundary'        => ['expired', self::EMAIL_CHANGE_CREDENTIAL],
            'consumed grant replay'  => ['consumed', self::EMAIL_CHANGE_CREDENTIAL],
            'revoked grant replay'   => ['revoked', self::EMAIL_CHANGE_CREDENTIAL],
            'mismatched destination' => ['mismatched', self::EMAIL_CHANGE_CREDENTIAL],
            'missing user'           => ['missing_user', self::EMAIL_CHANGE_CREDENTIAL],
            'missing reservation'    => ['missing_reservation', self::EMAIL_CHANGE_CREDENTIAL],
            'missing grant'          => ['missing_grant', self::EMAIL_CHANGE_CREDENTIAL]
        ];
    }

    private function activeUserFor(string $email, ?UserId $userId = null): User
    {
        $user = User::invite(
            $userId ?? UserId::generate(),
            EmailAddress::fromString($email),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $user->activate(
            PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        return $user;
    }

    private function assertPasswordChangeFailureIsRedacted(
        InMemoryEventDispatcher $eventDispatcher,
        UserId $userId,
        string $errorMessage,
        string $currentPassword,
        string $newPassword
    ): void {
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(
            PasswordManagement::class.'::changePassword',
            $eventDispatcher->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $userId->toString()],
            $eventDispatcher->events()[0]->getRedactedCommandData()
        );
        self::assertSame('Password change failed.', $eventDispatcher->events()[0]->getErrorMessage());
        self::assertNotSame($errorMessage, $eventDispatcher->events()[0]->getErrorMessage());
        self::assertStringNotContainsString($currentPassword, serialize($eventDispatcher->events()[0]->toArray()));
        self::assertStringNotContainsString($newPassword, serialize($eventDispatcher->events()[0]->toArray()));
    }

    private function assertEmailConfirmationFailureIsRedacted(
        InMemoryEventDispatcher $eventDispatcher,
        UserId $userId,
        string $credential
    ): void {
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(
            EmailChangeConfirmation::class.'::confirmEmail',
            $eventDispatcher->events()[0]->getCommandClass()
        );
        self::assertSame(
            ['user_id' => $userId->toString()],
            $eventDispatcher->events()[0]->getRedactedCommandData()
        );
        self::assertSame('Email change confirmation failed.', $eventDispatcher->events()[0]->getErrorMessage());
        $serializedFailure = serialize($eventDispatcher->events()[0]->toArray());
        if ($credential !== '') {
            self::assertStringNotContainsString($credential, $serializedFailure);
        }

        self::assertStringNotContainsString('new@example.test', $serializedFailure);
        self::assertStringNotContainsString('ciphertext:confirm-email-once', $serializedFailure);
    }

    private function emailChangeGrant(UserId $userId): EmailChangeGrant
    {
        return EmailChangeGrant::issue(
            $userId,
            EmailChangeCredential::fromString(self::EMAIL_CHANGE_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-19T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:confirm-email-once',
            1
        );
    }

    private function grant(UserId $userId): ActivationGrant
    {
        return ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $userId,
            ActivationCredential::fromString(self::ACTIVATION_CREDENTIAL),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activation'
        );
    }

    private function refreshCredential(): RefreshCredential
    {
        return RefreshCredential::fromString(self::REFRESH_CREDENTIAL);
    }

    private function session(User $user, RefreshCredential $credential, bool $remembered): RefreshSession
    {
        $createdAt = new DateTimeImmutable('2026-08-19T11:00:00+00:00');

        return RefreshSession::start(
            RefreshSessionId::generate(),
            $user->getId(),
            $credential,
            $createdAt,
            new DateTimeImmutable($remembered ? '2026-09-03T11:00:00+00:00' : '2026-08-20T11:00:00+00:00'),
            new DateTimeImmutable($remembered ? '2026-09-18T11:00:00+00:00' : '2026-08-21T11:00:00+00:00'),
            $user->getAuthenticationVersion(),
            $remembered
        );
    }

    private function assertTokenSet(
        TokenSet $tokenSet,
        User $user,
        RefreshSession $refreshSession,
        bool $remembered
    ): void {
        self::assertSame($user->getId(), $tokenSet->getUserId());
        self::assertSame($refreshSession->getId(), $tokenSet->getRefreshSessionId());
        self::assertTrue($refreshSession->matchesCredential($tokenSet->getRefreshCredential()));
        self::assertSame($tokenSet->getRefreshCredential()->digest(), $refreshSession->getCredentialDigest());
        self::assertSame($refreshSession->getAbsoluteExpiresAt(), $tokenSet->getRefreshExpiresAt());
        self::assertSame($remembered, $tokenSet->isRemembered());
        self::assertSame('encoded.jwt.token', $tokenSet->getAccessToken()->toString());
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenSet->getAccessTokenExpiresAt());
        self::assertTrue($refreshSession->isUsableAt(new DateTimeImmutable('2026-08-19T12:00:00+00:00')));
        self::assertLessThanOrEqual($refreshSession->getAbsoluteExpiresAt(), $refreshSession->getIdleExpiresAt());
        self::assertInstanceOf(DateTimeImmutable::class, $refreshSession->getCreatedAt());
    }

    private function authentication(
        UserRepository $userRepository,
        ActivationGrantRepository $activationGrantRepository,
        RefreshSessionRepository $refreshSessionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        ?Clock $authenticationClock = null,
        ?LoginThrottle $loginThrottle = null,
        ?PasswordHasher $passwordHasher = null,
        ?PasswordValidator $passwordValidator = null,
        ?TokenEncoder $tokenEncoder = null,
        ?RefreshCredentialGenerator $refreshCredentialGenerator = null,
        ?AuthenticationTokenPolicy $tokenPolicy = null
    ): UserAuthentication {
        $passwordSecurity = new TestPasswordSecurity();
        if (
            $userRepository instanceof InMemoryUserRepository
            && $refreshSessionRepository instanceof InMemoryRefreshSessionRepository
        ) {
            $userRepository->bindRefreshSessionRepository($refreshSessionRepository);
        }

        return new UserAuthentication(
            $userRepository,
            $activationGrantRepository,
            $unitOfWork,
            $authenticationClock ?? new FixedClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $loginThrottle ?? new FixedLoginThrottle(true),
            $refreshCredentialGenerator ?? new FixedRefreshCredentialGenerator($this->refreshCredential()),
            $passwordHasher ?? $passwordSecurity,
            $passwordValidator ?? $passwordSecurity,
            new TokenIssuer(
                $tokenEncoder ?? new RecordingTokenEncoder(),
                $tokenPolicy ?? AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'))
            ),
            PasswordHash::fromString(password_hash('dummy-password', PASSWORD_DEFAULT)),
            $eventDispatcher
        );
    }

    private function passwordManagement(
        UserRepository $userRepository,
        RefreshSessionRepository $refreshSessionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        ?Clock $authenticationClock = null,
        ?PasswordHasher $passwordHasher = null,
        ?PasswordValidator $passwordValidator = null,
        ?PasswordResetGrantRepository $passwordResetGrantRepository = null,
        ?AuditEvidenceRepository $auditEvidenceRepository = null
    ): PasswordManagement {
        $passwordSecurity = new TestPasswordSecurity();

        return new PasswordManagement(
            $userRepository,
            $passwordResetGrantRepository ?? new InMemoryPasswordResetGrants($unitOfWork),
            new SessionRevocationService($refreshSessionRepository),
            $auditEvidenceRepository ?? new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $authenticationClock ?? new FixedClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $passwordHasher ?? $passwordSecurity,
            $passwordValidator ?? $passwordSecurity,
            PasswordHash::fromString(password_hash('dummy-password', PASSWORD_DEFAULT)),
            $eventDispatcher
        );
    }

    private function sessionLifecycle(
        UserRepository $userRepository,
        RefreshSessionRepository $refreshSessionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        ?Clock $authenticationClock = null,
        ?TokenEncoder $tokenEncoder = null,
        ?RefreshCredentialGenerator $refreshCredentialGenerator = null,
        ?AuthenticationTokenPolicy $tokenPolicy = null
    ): SessionLifecycle {
        return new SessionLifecycle(
            $userRepository,
            $refreshSessionRepository,
            new SessionRevocationService($refreshSessionRepository),
            $unitOfWork,
            $authenticationClock ?? new FixedClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $refreshCredentialGenerator ?? new FixedRefreshCredentialGenerator($this->refreshCredential()),
            new TokenIssuer(
                $tokenEncoder ?? new RecordingTokenEncoder(),
                $tokenPolicy ?? AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'))
            ),
            $tokenPolicy ?? AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            $eventDispatcher
        );
    }

    private function emailChangeConfirmation(
        UserRepository $userRepository,
        RefreshSessionRepository $refreshSessionRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher,
        ?Clock $authenticationClock = null,
        ?PasswordResetGrantRepository $passwordResetGrantRepository = null,
        ?AuditEvidenceRepository $auditEvidenceRepository = null,
        ?EmailChangeGrantRepository $emailChangeGrantRepository = null
    ): EmailChangeConfirmation {
        return new EmailChangeConfirmation(
            $userRepository,
            $emailChangeGrantRepository ?? new InMemoryEmailChangeGrantRepository($unitOfWork),
            $passwordResetGrantRepository ?? new InMemoryPasswordResetGrants($unitOfWork),
            new SessionRevocationService($refreshSessionRepository),
            $auditEvidenceRepository ?? new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $authenticationClock ?? new FixedClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $eventDispatcher
        );
    }
}
