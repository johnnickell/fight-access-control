<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\LoginRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Auth\Security\PasswordHasher;
use Fight\Common\Application\Auth\Security\PasswordValidator;
use Fight\Common\Application\Auth\Security\TokenEncoder;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedAuthenticationClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessToken::class)]
#[CoversClass(AuthenticationService::class)]
#[CoversClass(AuthenticationTokenPolicy::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(RefreshCredential::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class AuthenticationServiceTest extends TestCase
{
    private const string ACTIVATION_CREDENTIAL = 'activate-once';

    private const string REFRESH_CREDENTIAL = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private const string SIBLING_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    /**
     * @return array<string, array{?User, bool}>
     */
    public static function rejectedLoginStates(): array
    {
        $activeUser = static function (string $email): User {
            $user = User::invite(UserId::generate(), EmailAddress::fromString($email));
            $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

            return $user;
        };

        return [
            'unknown identity' => [null, true],
            'pending identity' => [
                User::invite(UserId::generate(), EmailAddress::fromString('pending@example.test')),
                true,
            ],
            'disabled identity' => [UserFixture::withState('disabled@example.test', UserState::DISABLED), true],
            'deleted identity' => [UserFixture::withState('deleted@example.test', UserState::DELETED), true],
            'throttled active identity' => [$activeUser('throttled@example.test'), false],
        ];
    }

    public function test_that_activation_hashes_secrets_and_commits_a_token_set_before_safe_publication(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('activate@example.test'));
        $users->add($user);
        $grants->add($this->grant($user->getId()));
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $tokenEncoder = new RecordingTokenEncoder();
        $service = $this->service($users, $grants, $sessions, $unitOfWork, $events, tokenEncoder: $tokenEncoder);

        $tokenSet = $service->activate(
            $user->getId(),
            self::ACTIVATION_CREDENTIAL,
            'a sufficiently long initial password',
            true
        );

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(UserState::ACTIVE, $user->getState());
        self::assertTrue(password_verify('a sufficiently long initial password', $user->getPasswordHash()->toString()));
        self::assertTrue($grants->all()[0]->isConsumed());
        self::assertCount(1, $sessions->all());
        $this->assertTokenSet($tokenSet, $user, $sessions->all()[0], true);
        self::assertSame('access', $tokenEncoder->claims['type']);
        self::assertSame($user->getId()->toString(), $tokenEncoder->claims['sub']);
        self::assertSame($sessions->all()[0]->getId()->toString(), $tokenEncoder->claims['sid']);
        self::assertSame(1, $tokenEncoder->claims['auth_version']);
        self::assertSame(1787140800, $tokenEncoder->claims['iat']);
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenEncoder->expiration);
        self::assertInstanceOf(UserActivated::class, $events->events()[0]);
        self::assertStringNotContainsString(self::ACTIVATION_CREDENTIAL, serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString(
            'a sufficiently long initial password',
            serialize($events->events()[0]->toArray())
        );
    }

    public function test_that_activation_rejects_a_wrong_credential_with_only_redacted_context(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('activate@example.test'));
        $users->add($user);
        $grants->add($this->grant($user->getId()));
        $service = $this->service($users, $grants, $sessions, $unitOfWork, $events);

        $this->expectException(LogicException::class);
        try {
            $service->activate($user->getId(), 'wrong-credential', 'a sufficiently long initial password');
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertFalse($grants->all()[0]->isConsumed());
            self::assertCount(0, $sessions->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame(AuthenticationService::class.'::activate', $events->events()[0]->getCommandClass());
            self::assertSame(['user_id' => $user->getId()->toString()], $events->events()[0]->getRedactedCommandData());
            self::assertStringNotContainsString('wrong-credential', serialize($events->events()[0]->toArray()));
        }
    }

    public function test_that_login_uses_fight_common_password_services_and_rehashes_after_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('person@example.test');
        $originalHash = $user->getPasswordHash()->toString();
        $users->add($user);
        $passwordSecurity = new TestPasswordSecurity(true);
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            passwordHasher: $passwordSecurity,
            passwordValidator: $passwordSecurity
        );

        $tokenSet = $service->login('PERSON@example.test', 'correct-secret');

        $this->assertTokenSet($tokenSet, $user, $sessions->all()[0], false);
        self::assertNotSame($originalHash, $user->getPasswordHash()->toString());
        self::assertTrue(password_verify('correct-secret', $user->getPasswordHash()->toString()));
        self::assertInstanceOf(UserLoggedIn::class, $events->events()[0]);
    }

    #[DataProvider('rejectedLoginStates')]
    public function test_that_all_ineligible_login_states_verify_once_and_fail_generically(
        ?User $user,
        bool $throttleAllows
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $email = 'unknown@example.test';
        if ($user instanceof User) {
            $users->add($user);
            $email = $user->getEmail()->canonical();
        }

        $passwordValidator = new class implements PasswordValidator {
            public int $validations = 0;

            public function validate(string $password, string $hash): bool
            {
                ++$this->validations;

                return false;
            }

            public function needsRehash(string $hash): bool
            {
                return false;
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            $events,
            loginThrottle: new FixedLoginThrottle($throttleAllows),
            passwordValidator: $passwordValidator
        );

        $this->expectException(LoginRejectedException::class);
        $this->expectExceptionMessage('Login rejected.');
        try {
            $service->login($email, 'wrong-secret');
        } finally {
            self::assertSame(1, $passwordValidator->validations);
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame([], $events->events()[0]->getRedactedCommandData());
            self::assertStringNotContainsString('wrong-secret', serialize($events->events()[0]->toArray()));
        }
    }

    public function test_that_refresh_revalidates_authority_and_returns_a_new_access_jwt(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = $this->activeUserFor('refresh@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($session);
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            new InMemoryEventDispatcher()
        );

        $tokenSet = $service->refresh(self::REFRESH_CREDENTIAL);

        $this->assertTokenSet($tokenSet, $user, $session, false);
        self::assertFalse($session->isRevoked());
    }

    public function test_that_refresh_rejects_an_authentication_version_mismatch_without_leaking_the_credential(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $userId = UserId::generate();
        $user = UserFixture::withIdAndAuthenticationVersion($userId, 'version@example.test', UserState::ACTIVE, 2);
        $users->add($user);
        $sessionOwner = $this->activeUserFor('session-owner@example.test', $userId);
        $sessions->add($this->session($sessionOwner, $this->refreshCredential(), false));
        $service = $this->service($users, new InMemoryActivationGrantRepository(), $sessions, $unitOfWork, $events);

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($events->events()[0]->toArray()));
        }
    }

    public function test_that_logout_revokes_only_the_credential_selected_session(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('logout@example.test');
        $users->add($user);
        $current = $this->session($user, $this->refreshCredential(), false);
        $siblingCredential = RefreshCredential::fromString(self::SIBLING_CREDENTIAL);
        $sibling = $this->session($user, $siblingCredential, true);
        $sessions->add($current);
        $sessions->add($sibling);

        $service = $this->service($users, new InMemoryActivationGrantRepository(), $sessions, $unitOfWork, $events);

        $service->logout(self::REFRESH_CREDENTIAL);

        self::assertTrue($current->isRevoked());
        self::assertFalse($sibling->isRevoked());
        self::assertInstanceOf(CurrentSessionLoggedOut::class, $events->events()[0]);
        self::assertSame($current->getId(), $events->events()[0]->getRefreshSessionId());
        $this->assertTokenSet($service->refresh(self::SIBLING_CREDENTIAL), $user, $sibling, true);
    }

    public function test_that_missing_logout_credentials_fail_with_redacted_context(): void
    {
        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(),
            new InMemoryRefreshSessionRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $service->logout(self::REFRESH_CREDENTIAL);
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame(AuthenticationService::class.'::logout', $events->events()[0]->getCommandClass());
            self::assertSame([], $events->events()[0]->getRedactedCommandData());
        }
    }

    public function test_that_starter_token_lifetimes_and_value_validation_are_explicit(): void
    {
        $issuedAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $policy = AuthenticationTokenPolicy::starterDefaults();

        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $policy->accessExpiresAt($issuedAt));
        self::assertEquals(
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            $policy->refreshIdleExpiresAt($issuedAt, false)
        );
        self::assertEquals(
            new DateTimeImmutable('2026-08-21T12:00:00+00:00'),
            $policy->refreshAbsoluteExpiresAt($issuedAt, false)
        );
        self::assertEquals(
            new DateTimeImmutable('2026-09-03T12:00:00+00:00'),
            $policy->refreshIdleExpiresAt($issuedAt, true)
        );
        self::assertEquals(
            new DateTimeImmutable('2026-09-18T12:00:00+00:00'),
            $policy->refreshAbsoluteExpiresAt($issuedAt, true)
        );
        self::assertSame(self::REFRESH_CREDENTIAL, $this->refreshCredential()->toString());
        self::assertSame(64, strlen($this->refreshCredential()->digest()));
        self::assertSame('encoded.jwt.token', AccessToken::fromString('encoded.jwt.token')->toString());

        $this->expectException(DomainException::class);
        AccessToken::fromString('');
    }

    public function test_that_refresh_credentials_and_session_lifetimes_reject_invalid_values(): void
    {
        try {
            RefreshCredential::fromString('not-a-refresh-credential');
            self::fail('Expected invalid refresh credential rejection.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(DomainException::class, $domainException);
        }

        $this->expectException(InvalidArgumentException::class);
        RefreshSession::start(
            RefreshSessionId::generate(),
            UserId::generate(),
            $this->refreshCredential(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T12:00:00+00:00'),
            1,
            false
        );
    }

    private function activeUserFor(string $email, ?UserId $userId = null): User
    {
        $user = User::invite($userId ?? UserId::generate(), EmailAddress::fromString($email));
        $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

        return $user;
    }

    private function grant(UserId $userId): ActivationGrant
    {
        return ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString(self::ACTIVATION_CREDENTIAL),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00')
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

    private function service(
        UserRepository $users,
        ActivationGrantRepository $grants,
        RefreshSessionRepository $sessions,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events,
        ?LoginThrottle $loginThrottle = null,
        ?PasswordHasher $passwordHasher = null,
        ?PasswordValidator $passwordValidator = null,
        ?TokenEncoder $tokenEncoder = null,
        ?RefreshCredentialGenerator $refreshCredentialGenerator = null
    ): AuthenticationService {
        $passwordSecurity = new TestPasswordSecurity();

        return new AuthenticationService(
            $users,
            $grants,
            $sessions,
            $unitOfWork,
            new FixedAuthenticationClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $loginThrottle ?? new FixedLoginThrottle(true),
            $refreshCredentialGenerator ?? new FixedRefreshCredentialGenerator($this->refreshCredential()),
            $passwordHasher ?? $passwordSecurity,
            $passwordValidator ?? $passwordSecurity,
            $tokenEncoder ?? new RecordingTokenEncoder(),
            AuthenticationTokenPolicy::starterDefaults(),
            PasswordHash::fromString(password_hash('dummy-password', PASSWORD_DEFAULT)),
            $events
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
}
