<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeleteUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeleteUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserDeleted;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service\FixedRefreshSessionClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(DeleteUserHandler::class)]
#[CoversClass(DeleteUser::class)]
#[CoversClass(UserDeleted::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(SessionRevocationService::class)]
final class DeleteUserHandlerTest extends TestCase
{
    public function test_that_delete_atomically_soft_deletes_revokes_sessions_and_retains_identity(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('delete@example.test', UserState::ACTIVE);
        $users->add($user);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $sessions->add($this->session(RefreshSessionId::generate(), $user->getId()));

        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = $this->handler($users, $sessions, $audit, $unitOfWork, $events);
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new DeleteUser($actorId, $user->getId())));

        $stored = $users->getById($user->getId());
        self::assertSame(UserState::DELETED, $stored?->getState());
        $retained = $users->getByEmail(EmailAddress::fromString('delete@example.test'));
        self::assertInstanceOf(User::class, $retained);
        self::assertSame($user->getId(), $retained->getId());
        self::assertSame(UserState::DELETED, $retained->getState());
        self::assertTrue($sessions->all()[0]->isRevoked());
        self::assertSame('user.deleted', $audit->all()[0]->action());
        self::assertSame($actorId->toString(), $audit->all()[0]->actorId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserDeleted::class, $events->events()[0]);
    }

    public function test_that_a_disabled_identity_can_also_be_deleted(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('delete-disabled@example.test', UserState::DISABLED);
        $users->add($user);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->handler($users, $sessions, $audit, $unitOfWork, $events)->handle(
            CommandMessage::create(new DeleteUser(UserId::generate(), $user->getId()))
        );

        self::assertSame(UserState::DELETED, $users->getById($user->getId())?->getState());
    }

    public function test_that_the_command_and_event_round_trip(): void
    {
        self::assertSame(DeleteUser::class, DeleteUserHandler::commandRegistration());

        $actorId = UserId::generate();
        $userId = UserId::generate();
        $command = new DeleteUser($actorId, $userId);

        self::assertEquals($command, DeleteUser::fromArray($command->toArray()));

        $event = new UserDeleted($actorId, $userId);
        self::assertEquals($event, UserDeleted::fromArray($event->toArray()));
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($userId, $event->getUserId());
    }

    public function test_that_missing_command_and_event_data_is_rejected(): void
    {
        foreach (['actor_id', 'user_id'] as $missing) {
            $data = [
                'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
            ];
            unset($data[$missing]);

            try {
                DeleteUser::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }
        }

        foreach (['actor_id', 'user_id'] as $missing) {
            $data = [
                'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
            ];
            unset($data[$missing]);

            try {
                UserDeleted::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(4);
    }

    public function test_that_an_ineligible_identity_cannot_be_deleted(): void
    {
        foreach ([UserState::PENDING_ACTIVATION, UserState::DELETED] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('delete-'.$state->value.'@example.test', $state);
            $users->add($user);
            $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler($users, $sessions, $audit, $unitOfWork, $events)->handle(
                    CommandMessage::create(new DeleteUser(UserId::generate(), $user->getId()))
                );
                self::fail('An ineligible identity was deleted.');
            } catch (UserLifecycleException) {
                self::assertSame($state, $users->getById($user->getId())?->getState());
                self::assertSame([], $audit->all());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_that_a_missing_identity_is_rejected_without_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $sessions, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new DeleteUser(UserId::generate(), UserId::generate()))
            );
            self::fail('A missing identity was deleted.');
        } catch (UserLifecycleException) {
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('delete@example.test', UserState::ACTIVE);
        $users->add($user);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $sessions, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new DeleteUser(UserId::generate(), $user->getId()))
            );
            self::fail('A stale lifecycle state was replaced.');
        } catch (LogicException) {
            self::assertSame(UserState::ACTIVE, $users->getById($user->getId())?->getState());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryRefreshSessionRepository $sessions,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): DeleteUserHandler {
        return new DeleteUserHandler(
            $users,
            new SessionRevocationService($sessions),
            new FixedRefreshSessionClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $audit,
            $unitOfWork,
            $events
        );
    }

    private function session(RefreshSessionId $refreshSessionId, UserId $userId): RefreshSession
    {
        return RefreshSession::start(
            $refreshSessionId,
            $userId,
            RefreshCredential::fromString(str_repeat('a', 64)),
            new DateTimeImmutable('2026-08-19T08:00:00+00:00'),
            new DateTimeImmutable('2026-08-21T08:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T08:00:00+00:00'),
            1,
            false
        );
    }
}
