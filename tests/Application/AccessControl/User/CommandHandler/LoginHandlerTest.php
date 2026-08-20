<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use ArrayObject;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\LoginHandler;
use Fight\AccessControl\Application\AccessControl\User\Service\LoginThrottle;
use Fight\AccessControl\Application\AccessControl\User\Service\PasswordVerifier;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\Login;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserLoggedIn;
use Fight\AccessControl\Domain\AccessControl\User\Exception\LoginRejectedException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedLoginThrottle;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\HashPasswordVerifier;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LoginHandler::class)]
#[CoversClass(Login::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(UserLoggedIn::class)]
#[CoversClass(User::class)]
final class LoginHandlerTest extends TestCase
{
    /**
     * @return array<string, array{?User, bool, bool}>
     */
    public static function rejectedLoginCases(): array
    {
        $pending = User::invite(UserId::generate(), EmailAddress::fromString('pending@example.test'));

        return [
            'unknown email' => [null, true, true],
            'invalid secret' => [self::activeUserFor('invalid@example.test'), true, false],
            'pending identity' => [$pending, true, true],
            'disabled identity' => [UserFixture::withState('disabled@example.test', UserState::DISABLED), true, true],
            'deleted identity' => [UserFixture::withState('deleted@example.test', UserState::DELETED), true, true],
            'throttle denial' => [self::activeUserFor('throttled@example.test'), false, true],
        ];
    }

    public function test_that_an_active_login_commits_a_session_before_publishing_a_safe_outcome(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(function ($event) use ($unitOfWork): void {
            self::assertInstanceOf(UserLoggedIn::class, $event);
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $user = $this->activeUser();
        $users->add($user);
        $handler = new LoginHandler(
            $users,
            $sessions,
            $unitOfWork,
            new FixedLoginClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            new FixedLoginThrottle(true),
            new HashPasswordVerifier(),
            $events
        );

        $handler->handle(CommandMessage::create(new Login($user->getEmail(), 'correct-secret')));

        self::assertSame(Login::class, LoginHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $sessions->all());
        self::assertSame($user->getId(), $sessions->all()[0]->getUserId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserLoggedIn::class, $events->events()[0]);
        self::assertSame($user->getId(), $events->events()[0]->getUserId());
        self::assertSame($sessions->all()[0]->getId(), $events->events()[0]->getRefreshSessionId());
        self::assertEquals(
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            $events->events()[0]->getLoggedInAt()
        );
        self::assertStringNotContainsString('correct-secret', serialize($events->events()[0]->toArray()));
    }

    #[DataProvider('rejectedLoginCases')]
    public function test_that_rejected_login_paths_are_generic_and_do_not_retain_the_submitted_secret(
        ?User $user,
        bool $throttleAllows,
        bool $expectsDummyVerification
    ): void {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $email = EmailAddress::fromString('person@example.test');
        if ($user instanceof User) {
            $users->add($user);
            $email = $user->getEmail();
        }

        $passwordVerifier = new class implements PasswordVerifier {
            public int $dummyVerifications = 0;

            public int $passwordVerifications = 0;

            public function matches(string $secret, PasswordHash $passwordHash): bool
            {
                ++$this->passwordVerifications;

                return false;
            }

            public function matchesDummy(string $secret): bool
            {
                ++$this->dummyVerifications;

                return false;
            }
        };
        $handler = new LoginHandler(
            $users,
            $sessions,
            $unitOfWork,
            new FixedLoginClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            new FixedLoginThrottle($throttleAllows),
            $passwordVerifier,
            $events
        );

        $command = new Login($email, 'wrong-secret');

        $this->expectException(LoginRejectedException::class);
        $this->expectExceptionMessage('Login rejected.');
        try {
            $handler->handle(CommandMessage::create($command));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(0, $sessions->all());
            self::assertCount(1, $events->events());
            self::assertSame($expectsDummyVerification ? 1 : 0, $passwordVerifier->dummyVerifications);
            self::assertSame($expectsDummyVerification ? 0 : 1, $passwordVerifier->passwordVerifications);
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($command, $events->events()[0]->getCommand());
            self::assertSame(Login::class, $events->events()[0]->getCommand()::class);
            self::assertSame('Login rejected.', $events->events()[0]->getErrorMessage());
        }
    }

    public function test_that_throttle_runs_before_dummy_verification_when_denied(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $user = $this->activeUser();
        $users->add($user);
        /** @var ArrayObject<int, string> $trace */
        $trace = new ArrayObject();
        $loginThrottle = new readonly class ($trace) implements LoginThrottle {
            /** @param ArrayObject<int, string> $trace */
            public function __construct(private ArrayObject $trace)
            {
            }

            public function allows(EmailAddress $email): bool
            {
                $this->trace->append('throttle');

                return false;
            }
        };
        $passwordVerifier = new class ($trace) implements PasswordVerifier {
            public int $dummyVerifications = 0;

            public int $passwordVerifications = 0;

            /** @param ArrayObject<int, string> $trace */
            public function __construct(private readonly ArrayObject $trace)
            {
            }

            public function matches(string $secret, PasswordHash $passwordHash): bool
            {
                ++$this->passwordVerifications;
                $this->trace->append('password');

                return true;
            }

            public function matchesDummy(string $secret): bool
            {
                ++$this->dummyVerifications;
                $this->trace->append('dummy');

                return false;
            }
        };
        $handler = new LoginHandler(
            $users,
            $sessions,
            $unitOfWork,
            new FixedLoginClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $loginThrottle,
            $passwordVerifier,
            $events
        );

        $command = new Login($user->getEmail(), 'correct-secret');

        $this->expectException(LoginRejectedException::class);
        try {
            $handler->handle(CommandMessage::create($command));
        } finally {
            self::assertSame(['throttle', 'dummy'], $trace->getArrayCopy());
            self::assertSame(1, $passwordVerifier->dummyVerifications);
            self::assertSame(0, $passwordVerifier->passwordVerifications);
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(0, $sessions->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($command, $events->events()[0]->getCommand());
            self::assertSame('Login rejected.', $events->events()[0]->getErrorMessage());
        }
    }

    public function test_that_login_messages_and_safe_outcomes_round_trip_their_required_data(): void
    {
        $user = $this->activeUser();
        $command = new Login($user->getEmail(), 'submitted-secret');
        $event = new UserLoggedIn(
            $user->getId(),
            RefreshSessionId::generate(),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00')
        );

        $restoredCommand = Login::fromArray($command->toArray());

        self::assertSame($command->getEmail()->canonical(), $restoredCommand->getEmail()->canonical());
        self::assertSame($command->getSecret(), $restoredCommand->getSecret());
        self::assertSame($event->toArray(), UserLoggedIn::fromArray($event->toArray())->toArray());
    }

    public function test_that_login_messages_and_safe_outcomes_reject_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        Login::fromArray([]);
    }

    public function test_that_safe_outcomes_reject_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        UserLoggedIn::fromArray([]);
    }

    public function test_that_a_session_write_failure_is_redacted_and_rethrown(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = $this->activeUser();
        $users->add($user);
        $events = new InMemoryEventDispatcher();
        $handler = new LoginHandler(
            $users,
            new class implements RefreshSessionRepository {
                public function add(RefreshSession $refreshSession): void
                {
                    throw new RuntimeException('Session persistence failed.');
                }

                public function getById(RefreshSessionId $id): ?RefreshSession
                {
                    return null;
                }
            },
            $unitOfWork,
            new FixedLoginClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            new FixedLoginThrottle(true),
            new HashPasswordVerifier(),
            $events
        );

        $command = new Login($user->getEmail(), 'correct-secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Session persistence failed.');
        try {
            $handler->handle(CommandMessage::create($command));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            $event = $events->events()[0];
            self::assertSame($command, $event->getCommand());
            self::assertSame(Login::class, $event->getCommand()::class);
        }
    }

    private static function activeUserFor(string $email): User
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString($email));
        $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

        return $user;
    }

    private function activeUser(): User
    {
        return self::activeUserFor('person@example.test');
    }
}
