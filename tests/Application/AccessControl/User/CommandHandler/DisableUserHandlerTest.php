<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\SessionRevocationService;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DisableUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\Command\DisableUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserDisabled;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
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

#[CoversClass(DisableUserHandler::class)]
#[CoversClass(DisableUser::class)]
#[CoversClass(UserDisabled::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(SessionRevocationService::class)]
#[CoversClass(User::class)]
final class DisableUserHandlerTest extends TestCase
{
    public function test_that_disable_atomically_suspends_revokes_sessions_and_audits(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('disable@example.test', UserState::ACTIVE);
        $users->add($user);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $sessionId = RefreshSessionId::generate();
        $sessions->add($this->session($sessionId, $user->getId()));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork, $sessions): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertTrue($sessions->all()[0]->isRevoked());
        });
        $handler = $this->handler($users, $sessions, $audit, $unitOfWork, $events);
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new DisableUser($actorId, $user->getId())));

        self::assertSame(UserState::DISABLED, $users->getById($user->getId())?->getState());
        self::assertTrue($sessions->all()[0]->isRevoked());
        self::assertSame('user.disabled', $audit->all()[0]->action());
        self::assertSame($actorId->toString(), $audit->all()[0]->actorId());
        self::assertSame($user->getId(), $audit->all()[0]->userId());
        self::assertSame([], $audit->all()[0]->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserDisabled::class, $events->events()[0]);
        self::assertSame($actorId, $events->events()[0]->getActorId());
        self::assertSame($user->getId(), $events->events()[0]->getUserId());
    }

    public function test_that_the_command_and_event_round_trip_without_secret_material(): void
    {
        self::assertSame(DisableUser::class, DisableUserHandler::commandRegistration());

        $actorId = UserId::generate();
        $userId = UserId::generate();
        $command = new DisableUser($actorId, $userId);

        self::assertEquals($command, DisableUser::fromArray($command->toArray()));
        self::assertSame($actorId, $command->getActorId());
        self::assertSame($userId, $command->getUserId());

        $event = new UserDisabled($actorId, $userId);
        self::assertEquals($event, UserDisabled::fromArray($event->toArray()));
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
                DisableUser::fromArray($data);
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
                UserDisabled::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(4);
    }

    public function test_that_a_non_active_identity_cannot_be_disabled(): void
    {
        foreach ([UserState::PENDING_ACTIVATION, UserState::DISABLED, UserState::DELETED] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('disable-'.$state->value.'@example.test', $state);
            $users->add($user);
            $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler($users, $sessions, $audit, $unitOfWork, $events)->handle(
                    CommandMessage::create(new DisableUser(UserId::generate(), $user->getId()))
                );
                self::fail('A non-active identity was disabled.');
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
                CommandMessage::create(new DisableUser(UserId::generate(), UserId::generate()))
            );
            self::fail('A missing identity was disabled.');
        } catch (UserLifecycleException) {
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('disable@example.test', UserState::ACTIVE);
        $users->add($user);
        $sessions = new InMemoryRefreshSessionRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $sessions, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new DisableUser(UserId::generate(), $user->getId()))
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
    ): DisableUserHandler {
        return new DisableUserHandler(
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
