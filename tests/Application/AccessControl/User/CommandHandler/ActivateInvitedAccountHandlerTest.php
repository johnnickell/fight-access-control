<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ActivateInvitedAccountHandler;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\ActivateInvitedAccount;
use Fight\AccessControl\Domain\AccessControl\User\Event\RedactedCommandFailed;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserActivated;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserNotPendingActivationException;
use Fight\AccessControl\Domain\AccessControl\User\PasswordHash;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedActivationClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ActivateInvitedAccountHandler::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(RefreshSession::class)]
#[CoversClass(ActivateInvitedAccount::class)]
#[CoversClass(ActivationCredential::class)]
#[CoversClass(PasswordHash::class)]
#[CoversClass(RedactedCommandFailed::class)]
#[CoversClass(UserActivated::class)]
#[CoversClass(User::class)]
final class ActivateInvitedAccountHandlerTest extends TestCase
{
    public function test_that_valid_activation_commits_once_before_publishing_the_activation_event(): void
    {
        [$handler, $user, $grantRepository, $refreshSessionRepository, $unitOfWork, $events] = $this->readyHandler(
            assertPostCommitEvent: true
        );

        $passwordHash = $this->passwordHash();
        $handler->handle(CommandMessage::create(new ActivateInvitedAccount(
            $user->getId(),
            $this->credential('activate-once'),
            $passwordHash
        )));

        self::assertSame(ActivateInvitedAccount::class, ActivateInvitedAccountHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(UserState::ACTIVE, $user->getState());
        self::assertSame($passwordHash, $user->getPasswordHash());
        self::assertTrue($grantRepository->all()[0]->isConsumed());
        self::assertCount(1, $refreshSessionRepository->all());
        self::assertSame($user->getId(), $refreshSessionRepository->all()[0]->getUserId());
        self::assertSame(1, $refreshSessionRepository->all()[0]->getAuthenticationVersion());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserActivated::class, $events->events()[0]);
    }

    public function test_that_a_wrong_credential_leaves_no_partial_durable_state(): void
    {
        [$handler, $user, $grantRepository, $refreshSessionRepository, , $events] = $this->readyHandler();
        $passwordHash = $this->passwordHash();
        $command = CommandMessage::create(new ActivateInvitedAccount(
            $user->getId(),
            $this->credential('wrong-credential'),
            $passwordHash
        ));

        $this->expectException(LogicException::class);
        try {
            $handler->handle($command);
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertFalse($grantRepository->all()[0]->isConsumed());
            self::assertCount(0, $refreshSessionRepository->all());
            $event = $events->events()[0];
            self::assertInstanceOf(RedactedCommandFailed::class, $event);
            self::assertSame(ActivateInvitedAccount::class, $event->getCommandClass());
            self::assertSame(['user_id' => $user->getId()->toString()], $event->getRedactedCommandData());
            self::assertStringNotContainsString('wrong-credential', serialize($event->toArray()));
            self::assertStringNotContainsString($passwordHash->toString(), serialize($event->toArray()));
        }
    }

    public function test_that_an_expired_grant_leaves_no_partial_durable_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('alice@example.test'));
        $users = new InMemoryUserRepository($unitOfWork);
        $users->add($user);

        $expiredGrant = ActivationGrant::issue(
            $user->getId(),
            $this->credential('activate-once'),
            new DateTimeImmutable('2026-08-17T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00')
        );
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $users,
            new readonly class ($expiredGrant) implements ActivationGrantRepository {
                public function __construct(private ActivationGrant $grant)
                {
                }

                public function add(ActivationGrant $grant): void
                {
                }

                public function getByUserId(UserId $userId): ActivationGrant
                {
                    return $this->grant;
                }

                public function replace(
                    ActivationGrant $predecessor,
                    ActivationGrant $revokedPredecessor,
                    ActivationGrant $replacement
                ): void {
                }

                public function replaceConsumed(ActivationGrant $predecessor, ActivationGrant $consumedGrant): void
                {
                }
            },
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            $events
        );

        $this->expectException(LogicException::class);
        try {
            $handler->handle(CommandMessage::create(new ActivateInvitedAccount(
                $user->getId(),
                $this->credential('activate-once'),
                $this->passwordHash()
            )));
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        }
    }

    public function test_that_a_grant_for_another_user_leaves_no_partial_durable_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('alice@example.test'));
        $users = new InMemoryUserRepository($unitOfWork);
        $users->add($user);

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $users,
            new readonly class ($this->grant(UserId::generate())) implements ActivationGrantRepository {
                public function __construct(private ActivationGrant $grant)
                {
                }

                public function add(ActivationGrant $grant): void
                {
                }

                public function getByUserId(UserId $userId): ActivationGrant
                {
                    return $this->grant;
                }

                public function replace(
                    ActivationGrant $predecessor,
                    ActivationGrant $revokedPredecessor,
                    ActivationGrant $replacement
                ): void {
                }

                public function replaceConsumed(ActivationGrant $predecessor, ActivationGrant $consumedGrant): void
                {
                }
            },
            new InMemoryRefreshSessionRepository($unitOfWork),
            $unitOfWork,
            $events
        );

        $this->expectException(LogicException::class);
        try {
            $handler->handle(CommandMessage::create(new ActivateInvitedAccount(
                $user->getId(),
                $this->credential('activate-once'),
                $this->passwordHash()
            )));
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        }
    }

    public function test_that_replay_after_success_is_rejected_without_another_session(): void
    {
        [$handler, $user, $grantRepository, $refreshSessionRepository, , $events] = $this->readyHandler();
        $command = CommandMessage::create(new ActivateInvitedAccount(
            $user->getId(),
            $this->credential('activate-once'),
            $this->passwordHash()
        ));
        $handler->handle($command);

        $this->expectException(LogicException::class);
        try {
            $handler->handle($command);
        } finally {
            self::assertSame(UserState::ACTIVE, $user->getState());
            self::assertTrue($grantRepository->all()[0]->isConsumed());
            self::assertCount(1, $refreshSessionRepository->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[1]);
        }
    }

    public function test_that_a_non_pending_identity_cannot_be_activated_or_create_a_session(): void
    {
        [$handler, $user, $grantRepository, $refreshSessionRepository, , $events] = $this->readyHandler();
        $existingPasswordHash = $this->passwordHash();
        $user->activate($existingPasswordHash);

        $this->expectException(
            UserNotPendingActivationException::class
        );
        try {
            $handler->handle(CommandMessage::create(new ActivateInvitedAccount(
                $user->getId(),
                $this->credential('activate-once'),
                $this->passwordHash()
            )));
        } finally {
            self::assertSame($existingPasswordHash, $user->getPasswordHash());
            self::assertFalse($grantRepository->all()[0]->isConsumed());
            self::assertCount(0, $refreshSessionRepository->all());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        }
    }

    public function test_that_a_late_session_write_failure_rolls_back_activation_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('alice@example.test'));
        $users = new InMemoryUserRepository($unitOfWork);
        $users->add($user);

        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $grants->add($this->grant($user->getId()));

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $users,
            $grants,
            new class implements RefreshSessionRepository {
                public function add(RefreshSession $refreshSession): void
                {
                    throw new RuntimeException('Session storage is unavailable.');
                }

                public function getById(RefreshSessionId $id): ?RefreshSession
                {
                    return null;
                }
            },
            $unitOfWork,
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new ActivateInvitedAccount(
                $user->getId(),
                $this->credential('activate-once'),
                $this->passwordHash()
            )));
        } finally {
            self::assertSame(UserState::PENDING_ACTIVATION, $user->getState());
            self::assertFalse($grants->all()[0]->isConsumed());
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        }
    }

    public function test_that_a_success_event_dispatch_failure_rethrows_and_publishes_command_failure(): void
    {
        [$handler, $user, , , , $events] = $this->readyHandler(
            eventDispatcher: new InMemoryEventDispatcher(static function ($event): void {
                if ($event instanceof UserActivated) {
                    throw new RuntimeException('Activation event dispatch failed.');
                }
            })
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new ActivateInvitedAccount(
                $user->getId(),
                $this->credential('activate-once'),
                $this->passwordHash()
            )));
        } finally {
            self::assertInstanceOf(RedactedCommandFailed::class, $events->events()[0]);
        }
    }

    /** @return array{ActivateInvitedAccountHandler, User, InMemoryActivationGrantRepository, InMemoryRefreshSessionRepository, InMemoryUnitOfWork, InMemoryEventDispatcher} */
    private function readyHandler(
        ?InMemoryEventDispatcher $eventDispatcher = null,
        bool $assertPostCommitEvent = false
    ): array {
        $unitOfWork = new InMemoryUnitOfWork();
        $user = User::invite(UserId::generate(), EmailAddress::fromString('alice@example.test'));
        $users = new InMemoryUserRepository($unitOfWork);
        $users->add($user);

        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $grants->add($this->grant($user->getId()));

        $refreshSessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $events = $eventDispatcher ?? new InMemoryEventDispatcher(
            static function () use ($assertPostCommitEvent, $unitOfWork): void {
                if ($assertPostCommitEvent) {
                    self::assertTrue($unitOfWork->transactionCompleted);
                }
            }
        );

        return [
            $this->handler($users, $grants, $refreshSessions, $unitOfWork, $events),
            $user,
            $grants,
            $refreshSessions,
            $unitOfWork,
            $events,
        ];
    }

    private function handler(
        InMemoryUserRepository $users,
        ActivationGrantRepository $grants,
        RefreshSessionRepository $refreshSessions,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): ActivateInvitedAccountHandler {
        return new ActivateInvitedAccountHandler(
            $users,
            $grants,
            $refreshSessions,
            $unitOfWork,
            new FixedActivationClock(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $events
        );
    }

    private function grant(UserId $userId): ActivationGrant
    {
        return ActivationGrant::issue(
            $userId,
            $this->credential('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }

    private function passwordHash(): PasswordHash
    {
        $passwordHash = password_hash('initial-password', PASSWORD_DEFAULT);

        return PasswordHash::fromString($passwordHash);
    }

    private function credential(string $value): ActivationCredential
    {
        return ActivationCredential::fromString($value);
    }
}
