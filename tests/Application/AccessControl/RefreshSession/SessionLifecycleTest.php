<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession;

use Closure;
use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EmailChangeConfirmation;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Application\AccessControl\RefreshSession\SessionLifecycle;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Application\AccessControl\User\Authentication\TokenIssuer;
use Fight\AccessControl\Application\AccessControl\User\Authentication\UserAuthentication;
use Fight\AccessControl\Application\AccessControl\User\PasswordManagement;
use Fight\AccessControl\Application\AccessControl\User\Security\AccessToken;
use Fight\AccessControl\Application\AccessControl\User\Security\AuthenticationTokenPolicy;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshOutcome;
use Fight\AccessControl\Application\AccessControl\User\Security\RefreshResult;
use Fight\AccessControl\Application\AccessControl\User\Security\TokenSet;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetRejectedException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
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
use Fight\AccessControl\Domain\AccessControl\User\Exception\PasswordChangeRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\AuthenticationRequired as RefreshSessionNotFoundException;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Collection\ArrayList;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Authentication\SynchronousAuthenticationFixture;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Security\RecordingTokenEncoder;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AccessToken::class)]
#[CoversClass(UserAuthentication::class)]
#[CoversClass(PasswordManagement::class)]
#[CoversClass(SessionLifecycle::class)]
#[CoversClass(EmailChangeConfirmation::class)]
#[CoversClass(TokenIssuer::class)]
#[CoversClass(AuthenticationTokenPolicy::class)]
#[CoversClass(RefreshOutcome::class)]
#[CoversClass(RefreshResult::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(EmailChangeConfirmed::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(PasswordChanged::class)]
#[CoversClass(PasswordChangeRejectedException::class)]
#[CoversClass(PasswordResetCompleted::class)]
#[CoversClass(PasswordResetGrant::class)]
#[CoversClass(PasswordResetRejectedException::class)]
#[CoversClass(RefreshCredential::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(TokenSet::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class SessionLifecycleTest extends TestCase
{
    use SynchronousAuthenticationFixture;

    private const string ROTATED_CREDENTIAL = '1111111111111111111111111111111111111111111111111111111111111111';

    private const string SIBLING_CREDENTIAL = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private const string WINNER_CREDENTIAL = '2222222222222222222222222222222222222222222222222222222222222222';

    private const string SECOND_ROTATED_CREDENTIAL = '3333333333333333333333333333333333333333333333333333333333333333';

    #[DataProvider('refreshLifetimeCases')]
    public function test_that_refresh_rotates_once_without_changing_access_authority_or_absolute_lifetime(
        bool $remembered,
        string $expectedIdleExpiry,
        string $expectedAbsoluteExpiry
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $user = $this->activeUserFor('refresh@example.test');
        $userRepository->add($user);
        $session = $this->session($user, $this->refreshCredential(), $remembered);
        $refreshSessionRepository->add($session);
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
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: new InMemoryEventDispatcher(),
            tokenEncoder: $tokenEncoder,
            refreshCredentialGenerator: $credentialGenerator
        );

        $refreshResult = $service->refresh(self::REFRESH_CREDENTIAL);
        $tokenSet = $refreshResult->getTokenSet();

        $rotatedSession = $refreshSessionRepository->getById($session->getId());
        self::assertInstanceOf(RefreshSession::class, $rotatedSession);
        self::assertSame(RefreshOutcome::ROTATED, $refreshResult->getOutcome());
        self::assertInstanceOf(TokenSet::class, $tokenSet);
        self::assertSame(1, $credentialGenerator->calls);
        self::assertSame(self::ROTATED_CREDENTIAL, $tokenSet->getRefreshCredential()->toString());
        self::assertSame($rotatedSession, $refreshSessionRepository->getByCredential($rotatedCredential));
        self::assertNull($refreshSessionRepository->getByCredential($this->refreshCredential()));
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
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-conflict@example.test');
        $userRepository->add($user);
        $refreshSessionRepository->add($this->session($user, $this->refreshCredential(), false));
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
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
        self::assertSame([], $eventDispatcher->events());
    }

    public function test_that_refresh_replay_outside_the_conflict_window_revokes_the_session_before_failing(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-replay@example.test');
        $userRepository->add($user);
        $session = $this->session($user, $this->refreshCredential(), false)->rotate(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            new DateTimeImmutable('2026-08-19T11:59:54+00:00'),
            new DateTimeImmutable('2026-08-20T11:59:54+00:00')
        );
        $refreshSessionRepository->add($session);
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
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
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
        self::assertTrue($refreshSessionRepository->getById($session->getId())?->isRevoked());

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
        self::assertCount(2, $eventDispatcher->events());
        foreach ($eventDispatcher->events() as $event) {
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(SessionLifecycle::class.'::refresh', $event->getCommandClass());
            self::assertSame([], $event->getRedactedCommandData());
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($event->toArray()));
            self::assertStringNotContainsString(self::ROTATED_CREDENTIAL, serialize($event->toArray()));
        }
    }

    public function test_that_older_used_credential_replay_revokes_the_authoritative_session_family(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('older-refresh-replay@example.test');
        $userRepository->add($user);
        $session = $this->session($user, $this->refreshCredential(), false);
        $refreshSessionRepository->add($session);
        $credentialGenerator = new class ([
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL),
            RefreshCredential::fromString(self::SECOND_ROTATED_CREDENTIAL)
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
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
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

        $authoritativeSession = $refreshSessionRepository->getById($session->getId());
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
        self::assertCount(2, $eventDispatcher->events());
        foreach ($eventDispatcher->events() as $event) {
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(SessionLifecycle::class.'::refresh', $event->getCommandClass());
            self::assertSame([], $event->getRedactedCommandData());
            self::assertStringNotContainsString(self::REFRESH_CREDENTIAL, serialize($event->toArray()));
            self::assertStringNotContainsString(self::SECOND_ROTATED_CREDENTIAL, serialize($event->toArray()));
        }
    }

    public function test_that_expired_and_already_revoked_sessions_cannot_refresh(): void
    {
        foreach (['expired', 'revoked'] as $terminalState) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();
            $user = $this->activeUserFor($terminalState.'-refresh@example.test');
            $userRepository->add($user);
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

            $refreshSessionRepository->add($session);
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
            $service = $this->sessionLifecycle(
                userRepository: $userRepository,
                refreshSessionRepository: $refreshSessionRepository,
                unitOfWork: $unitOfWork,
                eventDispatcher: $eventDispatcher,
                refreshCredentialGenerator: $credentialGenerator
            );

            try {
                $service->refresh(self::REFRESH_CREDENTIAL);
                self::fail('Expected a terminal refresh session to remain unusable.');
            } catch (RefreshSessionNotFoundException $exception) {
                self::assertSame('The refresh session is not authoritative.', $exception->getMessage());
            }

            self::assertSame(0, $credentialGenerator->calls);
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
            self::assertSame([], $eventDispatcher->events()[0]->getRedactedCommandData());
        }
    }

    public function test_that_an_interleaved_stale_refresh_cannot_become_a_second_winner(): void
    {
        $userRepository = new InMemoryUserRepository();
        $storedRefreshSessionRepository = new InMemoryRefreshSessionRepository();
        $user = $this->activeUserFor('refresh-race@example.test');
        $userRepository->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedRefreshSessionRepository->add($originalSession);
        $racingRefreshSessionRepository = new class (
            $storedRefreshSessionRepository
        ) implements RefreshSessionRepository {
            public bool $interleaveNextReplace = false;

            public ?Closure $beforeReplace = null;

            public function __construct(private readonly InMemoryRefreshSessionRepository $refreshSessionRepository)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->refreshSessionRepository->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->refreshSessionRepository->getById($id);
            }

            /** @return ResultSet<RefreshSession> */
            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                return $this->refreshSessionRepository->getByUserId($userId, $at, $pagination);
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return $this->refreshSessionRepository->getAllActiveByUserId($userId, $at);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->refreshSessionRepository->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->refreshSessionRepository->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if ($this->interleaveNextReplace && $this->beforeReplace instanceof Closure) {
                    $this->interleaveNextReplace = false;
                    ($this->beforeReplace)();
                }

                return $this->refreshSessionRepository->replace($expected, $replacement);
            }
        };
        $winnerEventDispatcher = new InMemoryEventDispatcher();
        $loserEventDispatcher = new InMemoryEventDispatcher();
        $winnerService = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $racingRefreshSessionRepository,
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $winnerEventDispatcher,
            authenticationClock: new FixedClock(
                new DateTimeImmutable('2026-08-19T12:00:01+00:00')
            ),
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::WINNER_CREDENTIAL)
            )
        );
        $loserClock = new class implements Clock {
            private int $observations = 0;

            public function now(): DateTimeImmutable
            {
                $observedAt = '2026-08-19T12:00:00+00:00';
                if ($this->observations > 0) {
                    $observedAt = '2026-08-19T12:00:02+00:00';
                }

                ++$this->observations;

                return new DateTimeImmutable($observedAt);
            }
        };
        $loserService = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $racingRefreshSessionRepository,
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $loserEventDispatcher,
            authenticationClock: $loserClock,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );
        $winner = null;
        $racingRefreshSessionRepository->beforeReplace = static function () use (&$winner, $winnerService): void {
            $winner = $winnerService->refresh(self::REFRESH_CREDENTIAL);
        };
        $racingRefreshSessionRepository->interleaveNextReplace = true;

        $conflict = $loserService->refresh(self::REFRESH_CREDENTIAL);

        self::assertInstanceOf(RefreshResult::class, $winner);
        self::assertSame(RefreshOutcome::ROTATED, $winner->getOutcome());
        self::assertSame(
            self::WINNER_CREDENTIAL,
            $winner->getTokenSet()?->getRefreshCredential()->toString()
        );
        self::assertSame(RefreshOutcome::CONFLICT, $conflict->getOutcome());
        self::assertNull($conflict->getTokenSet());
        self::assertTrue($storedRefreshSessionRepository->getById($originalSession->getId())?->matchesCredential(
            RefreshCredential::fromString(self::WINNER_CREDENTIAL)
        ));
        self::assertNull($storedRefreshSessionRepository->getByCredential(
            RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
        ));
        self::assertFalse($storedRefreshSessionRepository->getById($originalSession->getId())->isRevoked());
        self::assertSame([], $winnerEventDispatcher->events());
        self::assertSame([], $loserEventDispatcher->events());
    }

    public function test_that_stale_rotation_cannot_resurrect_a_concurrently_revoked_session(): void
    {
        $userRepository = new InMemoryUserRepository();
        $storedRefreshSessionRepository = new InMemoryRefreshSessionRepository();
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-revocation-race@example.test');
        $userRepository->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedRefreshSessionRepository->add($originalSession);
        $racingRefreshSessionRepository = new class (
            $storedRefreshSessionRepository
        ) implements RefreshSessionRepository {
            public bool $revokedDuringReplace = false;

            public function __construct(private readonly InMemoryRefreshSessionRepository $refreshSessionRepository)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->refreshSessionRepository->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->refreshSessionRepository->getById($id);
            }

            /** @return ResultSet<RefreshSession> */
            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                return $this->refreshSessionRepository->getByUserId($userId, $at, $pagination);
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return $this->refreshSessionRepository->getAllActiveByUserId($userId, $at);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->refreshSessionRepository->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->refreshSessionRepository->getByUsedCredential($refreshCredential);
            }

            public function replace(RefreshSession $expected, RefreshSession $replacement): bool
            {
                if (!$this->revokedDuringReplace) {
                    $this->revokedDuringReplace = true;
                    $concurrentlyRevoked = $expected->revoke();
                    if (!$this->refreshSessionRepository->replace($expected, $concurrentlyRevoked)) {
                        throw new LogicException('Expected the interleaved revocation to win.');
                    }
                }

                return $this->refreshSessionRepository->replace($expected, $replacement);
            }
        };
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $racingRefreshSessionRepository,
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $eventDispatcher,
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

        $authoritativeSession = $storedRefreshSessionRepository->getById($originalSession->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());
        self::assertTrue($authoritativeSession->matchesCredential($this->refreshCredential()));
        self::assertNull(
            $storedRefreshSessionRepository->getByCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL))
        );
        self::assertTrue($racingRefreshSessionRepository->revokedDuringReplace);
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
    }

    public function test_that_non_conflicting_cas_contention_revokes_the_latest_authoritative_session(): void
    {
        $userRepository = new InMemoryUserRepository();
        $storedRefreshSessionRepository = new InMemoryRefreshSessionRepository();
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('refresh-terminal-race@example.test');
        $userRepository->add($user);
        $originalSession = $this->session($user, $this->refreshCredential(), false);
        $storedRefreshSessionRepository->add($originalSession);
        $racingRefreshSessionRepository = new class (
            $storedRefreshSessionRepository
        ) implements RefreshSessionRepository {
            public int $contentions = 0;

            public function __construct(private readonly InMemoryRefreshSessionRepository $refreshSessionRepository)
            {
            }

            public function add(RefreshSession $refreshSession): void
            {
                $this->refreshSessionRepository->add($refreshSession);
            }

            public function getById(RefreshSessionId $id): ?RefreshSession
            {
                return $this->refreshSessionRepository->getById($id);
            }

            /** @return ResultSet<RefreshSession> */
            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                return $this->refreshSessionRepository->getByUserId($userId, $at, $pagination);
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                return $this->refreshSessionRepository->getAllActiveByUserId($userId, $at);
            }

            public function getByCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->refreshSessionRepository->getByCredential($refreshCredential);
            }

            public function getByUsedCredential(RefreshCredential $refreshCredential): ?RefreshSession
            {
                return $this->refreshSessionRepository->getByUsedCredential($refreshCredential);
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
                        !$this->refreshSessionRepository->replace($expected, $firstWinner)
                        || !$this->refreshSessionRepository->replace($firstWinner, $secondWinner)
                    ) {
                        throw new LogicException('Expected both interleaved rotations to win in sequence.');
                    }

                    return false;
                }

                if ($this->contentions === 1 && $replacement->isRevoked()) {
                    ++$this->contentions;
                    $concurrentlyRevoked = $expected->revoke();
                    if (!$this->refreshSessionRepository->replace($expected, $concurrentlyRevoked)) {
                        throw new LogicException('Expected the interleaved revocation to win.');
                    }

                    return false;
                }

                return $this->refreshSessionRepository->replace($expected, $replacement);
            }
        };
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $racingRefreshSessionRepository,
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $eventDispatcher,
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

        $authoritativeSession = $storedRefreshSessionRepository->getById($originalSession->getId());
        self::assertInstanceOf(RefreshSession::class, $authoritativeSession);
        self::assertTrue($authoritativeSession->isRevoked());
        self::assertTrue($authoritativeSession->matchesCredential(RefreshCredential::fromString(
            '3333333333333333333333333333333333333333333333333333333333333333'
        )));
        self::assertNull(
            $storedRefreshSessionRepository->getByCredential(RefreshCredential::fromString(self::ROTATED_CREDENTIAL))
        );
        self::assertSame(2, $racingRefreshSessionRepository->contentions);
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame([], $eventDispatcher->events()[0]->getRedactedCommandData());
    }

    public function test_that_refresh_rejects_an_authentication_version_mismatch_without_leaking_the_credential(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $userId = UserId::generate();
        $user = UserFixture::withIdAndAuthenticationVersion($userId, 'version@example.test', UserState::ACTIVE, 2);
        $userRepository->add($user);
        $sessionOwner = $this->activeUserFor('session-owner@example.test', $userId);
        $refreshSessionRepository->add($this->session($sessionOwner, $this->refreshCredential(), false));
        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher
        );

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $service->refresh(self::REFRESH_CREDENTIAL);
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
            self::assertStringNotContainsString(
                self::REFRESH_CREDENTIAL,
                serialize($eventDispatcher->events()[0]->toArray())
            );
        }
    }

    public function test_that_logout_revokes_only_the_credential_selected_session(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $user = $this->activeUserFor('logout@example.test');
        $userRepository->add($user);
        $current = $this->session($user, $this->refreshCredential(), false);
        $siblingCredential = RefreshCredential::fromString(self::SIBLING_CREDENTIAL);
        $sibling = $this->session($user, $siblingCredential, true);
        $refreshSessionRepository->add($current);
        $refreshSessionRepository->add($sibling);

        $service = $this->sessionLifecycle(
            userRepository: $userRepository,
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: $unitOfWork,
            eventDispatcher: $eventDispatcher,
            refreshCredentialGenerator: new FixedRefreshCredentialGenerator(
                RefreshCredential::fromString(self::ROTATED_CREDENTIAL)
            )
        );

        $service->logout(self::REFRESH_CREDENTIAL);

        $revokedCurrent = $refreshSessionRepository->getById($current->getId());
        self::assertInstanceOf(RefreshSession::class, $revokedCurrent);
        self::assertFalse($current->isRevoked());
        self::assertTrue($revokedCurrent->isRevoked());
        self::assertSame(1, $revokedCurrent->getRevision());
        self::assertFalse($sibling->isRevoked());
        self::assertInstanceOf(CurrentSessionLoggedOut::class, $eventDispatcher->events()[0]);
        self::assertSame($current->getId(), $eventDispatcher->events()[0]->getRefreshSessionId());
        $siblingRefreshResult = $service->refresh(self::SIBLING_CREDENTIAL);
        $siblingTokenSet = $siblingRefreshResult->getTokenSet();
        $rotatedSibling = $refreshSessionRepository->getById($sibling->getId());
        self::assertInstanceOf(RefreshSession::class, $rotatedSibling);
        self::assertInstanceOf(TokenSet::class, $siblingTokenSet);
        $this->assertTokenSet($siblingTokenSet, $user, $rotatedSibling, true);
    }

    public function test_that_missing_logout_credentials_fail_with_redacted_context(): void
    {
        $eventDispatcher = new InMemoryEventDispatcher();
        $service = $this->sessionLifecycle(
            userRepository: new InMemoryUserRepository(),
            refreshSessionRepository: new InMemoryRefreshSessionRepository(),
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $eventDispatcher
        );

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $service->logout(self::REFRESH_CREDENTIAL);
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
            self::assertSame(SessionLifecycle::class.'::logout', $eventDispatcher->events()[0]->getCommandClass());
            self::assertSame([], $eventDispatcher->events()[0]->getRedactedCommandData());
        }
    }

    public function test_that_logout_fails_closed_when_the_session_disappears_during_revocation(): void
    {
        $eventDispatcher = new InMemoryEventDispatcher();
        $session = $this->session(
            $this->activeUserFor('logout-disappeared@example.test'),
            $this->refreshCredential(),
            false
        );
        $refreshSessionRepository = new readonly class ($session) implements RefreshSessionRepository {
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

            /** @return ResultSet<RefreshSession> */
            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                $refreshSessions = [];
                if ($this->session->getUserId()->equals($userId) && $this->session->isUsableAt($at)) {
                    $refreshSessions[] = $this->session;
                }

                return new ResultSet(
                    $pagination->page(),
                    $pagination->perPage(),
                    count($refreshSessions),
                    ArrayList::of(RefreshSession::class)->replace(array_slice(
                        $refreshSessions,
                        $pagination->offset(),
                        $pagination->limit()
                    ))
                );
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                if ($this->session->getUserId()->equals($userId) && $this->session->isUsableAt($at)) {
                    return [$this->session];
                }

                return [];
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
        $service = $this->sessionLifecycle(
            userRepository: new InMemoryUserRepository(),
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $eventDispatcher
        );

        try {
            $service->logout(self::REFRESH_CREDENTIAL);
            self::fail('Expected disappeared session state to fail closed.');
        } catch (StateConflict $stateConflict) {
            self::assertSame(
                'The refresh session lost authority during revocation.',
                $stateConflict->getMessage()
            );
        }

        self::assertFalse($session->isRevoked());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(SessionLifecycle::class.'::logout', $eventDispatcher->events()[0]->getCommandClass());
    }

    public function test_that_logout_fails_closed_after_bounded_authoritative_revision_contention(): void
    {
        $eventDispatcher = new InMemoryEventDispatcher();
        $session = $this->session(
            $this->activeUserFor('logout-contention@example.test'),
            $this->refreshCredential(),
            false
        );
        $refreshSessionRepository = new class ($session) implements RefreshSessionRepository {
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

            /** @return ResultSet<RefreshSession> */
            public function getByUserId(
                UserId $userId,
                DateTimeImmutable $at,
                Pagination $pagination
            ): ResultSet {
                $refreshSessions = [];
                if (
                    $this->authoritativeSession->getUserId()->equals($userId)
                    && $this->authoritativeSession->isUsableAt($at)
                ) {
                    $refreshSessions[] = $this->authoritativeSession;
                }

                return new ResultSet(
                    $pagination->page(),
                    $pagination->perPage(),
                    count($refreshSessions),
                    ArrayList::of(RefreshSession::class)->replace(array_slice(
                        $refreshSessions,
                        $pagination->offset(),
                        $pagination->limit()
                    ))
                );
            }

            public function getAllActiveByUserId(UserId $userId, DateTimeImmutable $at): array
            {
                if (
                    $this->authoritativeSession->getUserId()->equals($userId)
                    && $this->authoritativeSession->isUsableAt($at)
                ) {
                    return [$this->authoritativeSession];
                }

                return [];
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
        $service = $this->sessionLifecycle(
            userRepository: new InMemoryUserRepository(),
            refreshSessionRepository: $refreshSessionRepository,
            unitOfWork: new InMemoryUnitOfWork(),
            eventDispatcher: $eventDispatcher
        );

        try {
            $service->logout(self::REFRESH_CREDENTIAL);
            self::fail('Expected perpetual authoritative contention to fail closed.');
        } catch (StateConflict $stateConflict) {
            self::assertSame(
                'The refresh session changed during bounded revocation.',
                $stateConflict->getMessage()
            );
        }

        self::assertSame(3, $refreshSessionRepository->replaceAttempts);
        self::assertSame(3, $refreshSessionRepository->authoritativeReads);
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(RedactedCommandFailed::class, $eventDispatcher->events()[0]);
        self::assertSame(SessionLifecycle::class.'::logout', $eventDispatcher->events()[0]->getCommandClass());
        self::assertSame([], $eventDispatcher->events()[0]->getRedactedCommandData());
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
}
