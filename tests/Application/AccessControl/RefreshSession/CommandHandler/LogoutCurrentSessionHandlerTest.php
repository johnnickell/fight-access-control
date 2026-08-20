<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\CommandHandler\LogoutCurrentSessionHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\QueryHandler\RestoreAuthenticatedSessionHandler;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\CurrentRefreshSessionProvider;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Command\LogoutCurrentSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Event\CurrentSessionLoggedOut;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception\RefreshSessionNotFoundException;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\Query\RestoreAuthenticatedSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Query\QueryMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedCurrentRefreshSessionProvider;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(LogoutCurrentSessionHandler::class)]
#[CoversClass(LogoutCurrentSession::class)]
#[CoversClass(CurrentSessionLoggedOut::class)]
#[CoversClass(RefreshSession::class)]
final class LogoutCurrentSessionHandlerTest extends TestCase
{
    public function test_that_it_revokes_the_consumer_authenticated_current_session(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $users = new InMemoryUserRepository($unitOfWork);
        $user = $this->activeUser();
        $currentSessionId = RefreshSessionId::generate();
        $otherSessionId = RefreshSessionId::generate();
        $users->add($user);
        $sessions->add(RefreshSession::start($currentSessionId, $user->getId(), new DateTimeImmutable()));
        $sessions->add(RefreshSession::start($otherSessionId, $user->getId(), new DateTimeImmutable()));

        $events = new InMemoryEventDispatcher(function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new LogoutCurrentSessionHandler(
            new FixedCurrentRefreshSessionProvider($currentSessionId),
            $sessions,
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new LogoutCurrentSession()));

        self::assertSame(LogoutCurrentSession::class, LogoutCurrentSessionHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue($sessions->getById($currentSessionId)->isRevoked());
        self::assertFalse($sessions->getById($otherSessionId)->isRevoked());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CurrentSessionLoggedOut::class, $events->events()[0]);
        self::assertSame($currentSessionId, $events->events()[0]->getRefreshSessionId());

        $restoration = new RestoreAuthenticatedSessionHandler($sessions, $users);
        $revokedSessionQuery = QueryMessage::create(new RestoreAuthenticatedSession($currentSessionId));
        $otherSessionQuery = QueryMessage::create(new RestoreAuthenticatedSession($otherSessionId));
        $revokedSessionView = $restoration->handle($revokedSessionQuery);
        $otherSessionView = $restoration->handle($otherSessionQuery);

        self::assertNull($revokedSessionView);
        self::assertNotNull($otherSessionView);
    }

    public function test_that_missing_session_failures_dispatch_the_original_command_and_rethrow(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = new LogoutCurrentSessionHandler(
            new FixedCurrentRefreshSessionProvider(RefreshSessionId::generate()),
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            $events
        );
        $command = new LogoutCurrentSession();

        $this->expectException(RefreshSessionNotFoundException::class);
        try {
            $handler->handle(CommandMessage::create($command));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($command, $events->events()[0]->getCommand());
        }
    }

    public function test_that_repository_failures_dispatch_the_original_command_and_rethrow(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = new LogoutCurrentSessionHandler(
            new FixedCurrentRefreshSessionProvider(RefreshSessionId::generate()),
            new class implements RefreshSessionRepository {
                public function add(RefreshSession $refreshSession): void
                {
                }

                public function getById(RefreshSessionId $id): ?RefreshSession
                {
                    throw new RuntimeException('Session persistence failed.');
                }
            },
            $unitOfWork,
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Session persistence failed.');
        try {
            $handler->handle(CommandMessage::create(new LogoutCurrentSession()));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame('Session persistence failed.', $events->events()[0]->getErrorMessage());
        }
    }

    public function test_that_current_session_authority_failures_dispatch_the_original_command_and_rethrow(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $command = new LogoutCurrentSession();
        $failure = new RuntimeException('Current session authority is unavailable.');
        $handler = new LogoutCurrentSessionHandler(
            new readonly class ($failure) implements CurrentRefreshSessionProvider {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function getCurrentRefreshSessionId(): RefreshSessionId
                {
                    throw $this->failure;
                }
            },
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            $events
        );

        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('Expected the current session authority failure to be rethrown.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertFalse($unitOfWork->transactionCompleted);
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($command, $events->events()[0]->getCommand());
            self::assertSame($failure->getMessage(), $events->events()[0]->getErrorMessage());
        }
    }

    public function test_that_logout_messages_have_no_target_session_and_safe_outcomes_round_trip(): void
    {
        $refreshSessionId = RefreshSessionId::generate();
        $command = new LogoutCurrentSession();
        $event = new CurrentSessionLoggedOut($refreshSessionId);

        self::assertSame($command->toArray(), LogoutCurrentSession::fromArray($command->toArray())->toArray());
        self::assertSame($event->toArray(), CurrentSessionLoggedOut::fromArray($event->toArray())->toArray());
        self::assertSame([], $command->toArray());

        $this->expectException(DomainException::class);
        LogoutCurrentSession::fromArray(['refresh_session_id' => $refreshSessionId->toString()]);
    }

    public function test_that_the_current_session_logout_outcome_rejects_missing_data(): void
    {
        $this->expectException(DomainException::class);
        CurrentSessionLoggedOut::fromArray([]);
    }

    private function activeUser(): User
    {
        $user = User::invite(UserId::generate(), EmailAddress::fromString('person@example.test'));
        $user->activate(PasswordHash::fromString(password_hash('correct-secret', PASSWORD_DEFAULT)));

        return $user;
    }
}
