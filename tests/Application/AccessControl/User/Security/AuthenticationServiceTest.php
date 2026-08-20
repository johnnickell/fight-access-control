<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Security;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationService;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshOutcome;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
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
#[CoversClass(RefreshOutcome::class)]
#[CoversClass(RefreshResult::class)]
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

    private const string ROTATED_CREDENTIAL = '1111111111111111111111111111111111111111111111111111111111111111';

    private const string SIBLING_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private const string WINNER_CREDENTIAL = '2222222222222222222222222222222222222222222222222222222222222222';

    private const string SECOND_ROTATED_CREDENTIAL = '3333333333333333333333333333333333333333333333333333333333333333';

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

    /**
     * @return array<string, array{bool, string, string}>
     */
    public static function refreshLifetimeCases(): array
    {
        return [
            'ordinary session' => [false, '2026-08-20T12:00:00+00:00', '2026-08-21T11:00:00+00:00'],
            'remembered session' => [true, '2026-09-03T12:00:00+00:00', '2026-09-18T11:00:00+00:00'],
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

    #[DataProvider('refreshLifetimeCases')]
    public function test_that_refresh_rotates_once_without_changing_access_authority_or_absolute_lifetime(
        bool $remembered,
        string $expectedIdleExpiry,
        string $expectedAbsoluteExpiry
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = $this->activeUserFor('refresh@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), $remembered);
        $sessions->add($session);
        $rotatedCredential = RefreshCredential::fromString(self::ROTATED_CREDENTIAL);
        $credentialGenerator = new class ($rotatedCredential) implements RefreshCredentialGenerator {
            public int $calls = 0;

            public function __construct(private readonly RefreshCredential $credential)
            {
            }

            public function generate(): RefreshCredential
            {
                ++$this->calls;

                return $this->credential;
            }
        };
        $tokenEncoder = new RecordingTokenEncoder();
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            new InMemoryEventDispatcher(),
            tokenEncoder: $tokenEncoder,
            refreshCredentialGenerator: $credentialGenerator
        );

        $refreshResult = $service->refresh(self::REFRESH_CREDENTIAL);
        $tokenSet = $refreshResult->getTokenSet();

        $rotatedSession = $sessions->getById($session->getId());
        self::assertInstanceOf(RefreshSession::class, $rotatedSession);
        self::assertSame(RefreshOutcome::ROTATED, $refreshResult->getOutcome());
        self::assertInstanceOf(TokenSet::class, $tokenSet);
        self::assertSame(1, $credentialGenerator->calls);
        self::assertSame(self::ROTATED_CREDENTIAL, $tokenSet->getRefreshCredential()->toString());
        self::assertSame($rotatedSession, $sessions->getByCredential($rotatedCredential));
        self::assertNull($sessions->getByCredential($this->refreshCredential()));
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:00:00+00:00'), $rotatedSession->getLastActivityAt());
        self::assertEquals(new DateTimeImmutable($expectedIdleExpiry), $rotatedSession->getIdleExpiresAt());
        self::assertEquals(new DateTimeImmutable($expectedAbsoluteExpiry), $rotatedSession->getAbsoluteExpiresAt());
        self::assertSame($remembered, $rotatedSession->isRemembered());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($user->getId()->toString(), $tokenEncoder->claims['sub']);
        self::assertSame($session->getId()->toString(), $tokenEncoder->claims['sid']);
        self::assertSame(1, $tokenEncoder->claims['auth_version']);
        self::assertEquals(new DateTimeImmutable('2026-08-19T12:15:00+00:00'), $tokenEncoder->expiration);
        $this->assertTokenSet($tokenSet, $user, $rotatedSession, $remembered);
        self::assertFalse($session->isRevoked());
    }

    public function test_that_immediately_previous_refresh_credential_returns_a_secretless_conflict(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-conflict@example.test');
        $users->add($user);
        $sessions->add($this->session($user, $this->refreshCredential(), false));
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            tokenPolicy: AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        $winner = $service->refresh(self::REFRESH_CREDENTIAL);
        $conflict = $service->refresh(self::REFRESH_CREDENTIAL);

        self::assertSame(RefreshOutcome::ROTATED, $winner->getOutcome());
        self::assertNotNull($winner->getTokenSet());
        self::assertSame(RefreshOutcome::CONFLICT, $conflict->getOutcome());
        self::assertNull($conflict->getTokenSet());
        self::assertSame([], $events->events());
    }

    public function test_that_refresh_replay_outside_the_conflict_window_revokes_the_session_before_failing(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-replay@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), false)->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:59:54+00:00'),
            new DateTimeImmutable('2026-08-20T11:59:54+00:00')
        );
        $sessions->add($session);
        $credentialGenerator = new class () implements RefreshCredentialGenerator {
            public int $calls = 0;

            public function generate(): RefreshCredential
            {
                ++$this->calls;

                return RefreshCredential::fromString(
                    '2222222222222222222222222222222222222222222222222222222222222222'
                );
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            tokenPolicy: AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
            refreshCredentialGenerator: $credentialGenerator
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected replay outside the conflict window to fail.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertTrue($unitOfWork->transactionCompleted);
        self::assertTrue($sessions->getById($session->getId())?->isRevoked());

        try {
            $service->refresh(self::ROTATED_CREDENTIAL);
            self::fail('Expected a compromised session to remain unusable.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertSame(0, $credentialGenerator->calls);
        self::assertCount(2, $events->events());
        foreach ($events->events() as $event) {
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(AuthenticationService::class.'::refresh', $event->getCommandClass());
            self::assertSame([], $event->getRedactedCommandData());
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($event->toArray()));
            self::assertStringNotContainsString(self::ROTATED_CREDENTIAL, serialize($event->toArray()));
        }
    }

    public function test_that_older_used_credential_replay_revokes_the_authoritative_session_family(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('older-refresh-replay@example.test');
        $users->add($user);
        $session = $this->session($user, $this->refreshCredential(), false);
        $sessions->add($session);
        $credentialGenerator = new class ([
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            RefreshCredential::fromString(self::SECOND_ROTATED_CREDENTIAL),
        ]) implements RefreshCredentialGenerator {
            public int $calls = 0;

            /**
             * @param list<RefreshCredential> $credentials
             */
            public function __construct(private readonly array $credentials)
            {
            }

            public function generate(): RefreshCredential
            {
                $credential = $this->credentials[$this->calls];
                ++$this->calls;

                return $credential;
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository($unitOfWork),
            $sessions,
            $unitOfWork,
            $events,
            refreshCredentialGenerator: $credentialGenerator
        );

        $firstRotation = $service->refresh(self::REFRESH_CREDENTIAL);
        $secondRotation = $service->refresh(self::ROTATED_CREDENTIAL);

        self::assertSame(RefreshOutcome::ROTATED, $firstRotation->getOutcome());
        self::assertSame(RefreshOutcome::ROTATED, $secondRotation->getOutcome());
        self::assertSame(
            self::SECOND_ROTATED_CREDENTIAL,
            $secondRotation->getTokenSet()?->getRefreshCredential()->toString()
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected an older used credential to revoke the session family.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        $authoritativeSession = $sessions->getById($session->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());

        try {
            $service->refresh(self::SECOND_ROTATED_CREDENTIAL);
            self::fail('Expected the latest credential in a compromised family to remain unusable.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertSame(2, $credentialGenerator->calls);
        self::assertCount(2, $events->events());
        foreach ($events->events() as $event) {
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(AuthenticationService::class.'::refresh', $event->getCommandClass());
            self::assertSame([], $event->getRedactedCommandData());
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($event->toArray()));
            self::assertStringNotContainsString(self::SECOND_ROTATED_CREDENTIAL, serialize($event->toArray()));
        }
    }

    public function test_that_expired_and_already_revoked_sessions_cannot_refresh(): void
    {
        foreach (['expired', 'revoked'] as $terminalState) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $user = $this->activeUserFor($terminalState.'-refresh@example.test');
            $users->add($user);
            $session = RefreshSession::start(
                RefreshSessionId::generate(),
                $user->getId(),
                $this->refreshCredential(),
                new DateTimeImmutable('2026-08-19T10:00:00+00:00'),
                new DateTimeImmutable(
                    $terminalState === 'expired' ? '2026-08-19T11:59:59+00:00' : '2026-08-20T10:00:00+00:00'
                ),
                new DateTimeImmutable('2026-08-21T10:00:00+00:00'),
                $user->getAuthenticationVersion(),
                false
            );
            if ($terminalState === 'revoked') {
                $session = $session->revoke();
            }

            $sessions->add($session);
            $credentialGenerator = new class () implements RefreshCredentialGenerator {
                public int $calls = 0;

                public function generate(): RefreshCredential
                {
                    ++$this->calls;

                    return RefreshCredential::fromString(
                        '2222222222222222222222222222222222222222222222222222222222222222'
                    );
                }
            };
            $service = $this->service(
                $users,
                new InMemoryActivationGrantRepository($unitOfWork),
                $sessions,
                $unitOfWork,
                $events,
                refreshCredentialGenerator: $credentialGenerator
            );

            try {
                $service->refresh(self::REFRESH_CREDENTIAL);
                self::fail('Expected a terminal refresh session to remain unusable.');
            } catch (RefreshSessionNotFoundException $exception) {
                self::assertSame('The refresh session is not authoritative.', $exception->getMessage());
            }

            self::assertSame(0, $credentialGenerator->calls);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
            self::assertSame([], $events->events()[0]->getRedactedCommandData());
        }
    }

    public function test_that_an_interleaved_stale_refresh_cannot_become_a_second_winner(): void
    {
        $users = new InMemoryUserRepository();
        $storedSessions = new InMemoryRefreshSessionRepository();
        $user = $this->activeUserFor('refresh-race@example.test');
        $users->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedSessions->add($originalSession);
        $racingSessions = new class ($storedSessions) implements RefreshSessionRepository {
            public bool $interleaveNextReplace = false;

            public ?Closure $beforeReplace = null;

            public function __construct(private readonly InMemoryRefreshSessionRepository $sessions)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->sessions->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->sessions->getById($id);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if ($this->interleaveNextReplace && $this->beforeReplace instanceof Closure) {
                    $this->interleaveNextReplace = false;
                    ($this->beforeReplace)();
                }

                return $this->sessions->replace($expected, $replacement);
            }
        };
        $winnerEvents = new InMemoryEventDispatcher();
        $loserEvents = new InMemoryEventDispatcher();
        $winnerService = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $winnerEvents,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::WINNER_CREDENTIAL)
            )
        );
        $loserService = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $loserEvents,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );
        $winner = null;
        $racingSessions->beforeReplace = static function () use (&$winner, $winnerService): void {
            $winner = $winnerService->refresh(self::REFRESH_CREDENTIAL);
        };
        $racingSessions->interleaveNextReplace = true;

        $conflict = $loserService->refresh(self::REFRESH_CREDENTIAL);

        self::assertInstanceOf(RefreshResult::class, $winner);
        self::assertSame(RefreshOutcome::ROTATED, $winner->getOutcome());
        self::assertSame(
            self::WINNER_CREDENTIAL,
            $winner->getTokenSet()?->getRefreshCredential()->toString()
        );
        self::assertSame(RefreshOutcome::CONFLICT, $conflict->getOutcome());
        self::assertNull($conflict->getTokenSet());
        self::assertTrue($storedSessions->getById($originalSession->getId())?->matchesCredential(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL)
        ));
        self::assertNull($storedSessions->getByCredential(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
        ));
        self::assertSame([], $winnerEvents->events());
        self::assertSame([], $loserEvents->events());
    }

    public function test_that_stale_rotation_cannot_resurrect_a_concurrently_revoked_session(): void
    {
        $users = new InMemoryUserRepository();
        $storedSessions = new InMemoryRefreshSessionRepository();
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-revocation-race@example.test');
        $users->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedSessions->add($originalSession);
        $racingSessions = new class ($storedSessions) implements RefreshSessionRepository {
            public bool $revokedDuringReplace = false;

            public function __construct(private readonly InMemoryRefreshSessionRepository $sessions)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->sessions->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->sessions->getById($id);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if (!$this->revokedDuringReplace) {
                    $this->revokedDuringReplace = true;
                    $concurrentlyRevoked = $expected->revoke();
                    if (!$this->sessions->replace($expected, $concurrentlyRevoked)) {
                        throw new LogicException('Expected the interleaved revocation to win.');
                    }
                }

                return $this->sessions->replace($expected, $replacement);
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $events,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected the concurrent revocation to remain authoritative.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        $authoritativeSession = $storedSessions->getById($originalSession->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());
        self::assertTrue($authoritativeSession->matchesCredential($this->refreshCredential()));
        self::assertNull($storedSessions->getByCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL)));
        self::assertTrue($racingSessions->revokedDuringReplace);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
    }

    public function test_that_non_conflicting_cas_contention_revokes_the_latest_authoritative_session(): void
    {
        $users = new InMemoryUserRepository();
        $storedSessions = new InMemoryRefreshSessionRepository();
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-terminal-race@example.test');
        $users->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedSessions->add($originalSession);
        $racingSessions = new class ($storedSessions) implements RefreshSessionRepository {
            public int $contentions = 0;

            public function __construct(private readonly InMemoryRefreshSessionRepository $sessions)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->sessions->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->sessions->getById($id);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->sessions->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if ($this->contentions === 0) {
                    ++$this->contentions;
                    $firstWinner = $expected->rotate(
                        RefreshCredential::fromString(
                            '2222222222222222222222222222222222222222222222222222222222222222'
                        ),
                        new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                        new DateTimeImmutable('2026-08-20T12:00:00+00:00')
                    );
                    $secondWinner = $firstWinner->rotate(
                        RefreshCredential::fromString(
                            '3333333333333333333333333333333333333333333333333333333333333333'
                        ),
                        new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                        new DateTimeImmutable('2026-08-20T12:00:00+00:00')
                    );
                    if (
                        !$this->sessions->replace($expected, $firstWinner)
                        || !$this->sessions->replace($firstWinner, $secondWinner)
                    ) {
                        throw new LogicException('Expected both interleaved rotations to win in sequence.');
                    }

                    return false;
                }

                if ($this->contentions === 1 && $replacement->isRevoked()) {
                    ++$this->contentions;
                    $concurrentlyRevoked = $expected->revoke();
                    if (!$this->sessions->replace($expected, $concurrentlyRevoked)) {
                        throw new LogicException('Expected the interleaved revocation to win.');
                    }

                    return false;
                }

                return $this->sessions->replace($expected, $replacement);
            }
        };
        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $racingSessions,
            new InMemoryUnitOfWork(),
            $events,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
            self::fail('Expected non-conflicting stale contention to fail closed.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        $authoritativeSession = $storedSessions->getById($originalSession->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());
        self::assertTrue($authoritativeSession->matchesCredential(RefreshCredential::fromString(
            '3333333333333333333333333333333333333333333333333333333333333333'
        )));
        self::assertNull($storedSessions->getByCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL)));
        self::assertSame(2, $racingSessions->contentions);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame([], $events->events()[0]->getRedactedCommandData());
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

        $service = $this->service(
            $users,
            new InMemoryActivationGrantRepository(),
            $sessions,
            $unitOfWork,
            $events,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        $service->logout(self::REFRESH_CREDENTIAL);

        $revokedCurrent = $sessions->getById($current->getId());
        self::assertInstanceOf(RefreshSession::class, $revokedCurrent);
        self::assertFalse($current->isRevoked());
        self::assertTrue($revokedCurrent->isRevoked());
        self::assertSame(1, $revokedCurrent->getRevision());
        self::assertFalse($sibling->isRevoked());
        self::assertInstanceOf(CurrentSessionLoggedOut::class, $events->events()[0]);
        self::assertSame($current->getId(), $events->events()[0]->getRefreshSessionId());
        $siblingRefreshResult = $service->refresh(self::SIBLING_CREDENTIAL);
        $siblingTokenSet = $siblingRefreshResult->getTokenSet();
        $rotatedSibling = $sessions->getById($sibling->getId());
        self::assertInstanceOf(RefreshSession::class, $rotatedSibling);
        self::assertInstanceOf(TokenSet::class, $siblingTokenSet);
        $this->assertTokenSet($siblingTokenSet, $user, $rotatedSibling, true);
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

    public function test_that_logout_fails_closed_when_the_session_disappears_during_revocation(): void
    {
        $events = new InMemoryEventDispatcher();
        $session = $this->session(
            $this->activeUserFor('logout-disappeared@example.test'),
            $this->refreshCredential(),
            false
        );
        $sessions = new readonly class ($session) implements RefreshSessionRepository {
            public function __construct(private RefreshSession $session)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return null;
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->session->matchesCredential($refreshCredential) ? $this->session : null;
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return null;
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                return false;
            }
        };
        $service = $this->service(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(),
            $sessions,
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->logout(self::REFRESH_CREDENTIAL);
            self::fail('Expected disappeared session state to fail closed.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertFalse($session->isRevoked());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(AuthenticationService::class.'::logout', $events->events()[0]->getCommandClass());
    }

    public function test_that_logout_fails_closed_after_bounded_authoritative_revision_contention(): void
    {
        $events = new InMemoryEventDispatcher();
        $session = $this->session(
            $this->activeUserFor('logout-contention@example.test'),
            $this->refreshCredential(),
            false
        );
        $sessions = new class ($session) implements RefreshSessionRepository {
            public int $authoritativeReads = 0;

            public int $replaceAttempts = 0;

            public function __construct(private RefreshSession $authoritativeSession)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                if (!$this->authoritativeSession->getId()->equals($id)) {
                    return null;
                }

                ++$this->authoritativeReads;

                return $this->authoritativeSession;
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                $matchesCredential = $this->authoritativeSession->matchesCredential($refreshCredential);

                return $matchesCredential ? $this->authoritativeSession : null;
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return null;
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                ++$this->replaceAttempts;
                if ($this->replaceAttempts > 10) {
                    throw new LogicException('Revocation contention was not bounded.');
                }

                $this->authoritativeSession = $this->authoritativeSession->rotate(
                    RefreshCredential::fromString(self::contentionCredential($this->replaceAttempts)),
                    new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
                    new DateTimeImmutable('2026-08-20T12:00:00+00:00')
                );

                return false;
            }

            private static function contentionCredential(int $revision): string
            {
                return str_pad(dechex($revision), 64, '0', STR_PAD_LEFT);
            }
        };
        $service = $this->service(
            new InMemoryUserRepository(),
            new InMemoryActivationGrantRepository(),
            $sessions,
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->logout(self::REFRESH_CREDENTIAL);
            self::fail('Expected perpetual authoritative contention to fail closed.');
        } catch (RefreshSessionNotFoundException $refreshSessionNotFoundException) {
            self::assertSame(
                'The refresh session is not authoritative.',
                $refreshSessionNotFoundException->getMessage()
            );
        }

        self::assertSame(3, $sessions->replaceAttempts);
        self::assertSame(3, $sessions->authoritativeReads);
        self::assertCount(1, $events->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        self::assertSame(AuthenticationService::class.'::logout', $events->events()[0]->getCommandClass());
        self::assertSame([], $events->events()[0]->getRedactedCommandData());
    }

    public function test_that_starter_token_lifetimes_and_value_validation_are_explicit(): void
    {
        $issuedAt = new DateTimeImmutable('2026-08-19T12:00:00+00:00');
        $policy = AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S'));

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
        self::assertEquals(new DateInterval('PT5S'), $policy->refreshConflictWindow());
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
        ?RefreshCredentialGenerator $refreshCredentialGenerator = null,
        ?AuthenticationTokenPolicy $tokenPolicy = null
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
            $tokenPolicy ?? AuthenticationTokenPolicy::starterDefaults(new DateInterval('PT5S')),
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
