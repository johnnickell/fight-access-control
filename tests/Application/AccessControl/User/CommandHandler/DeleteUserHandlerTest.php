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
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository\InMemoryRefreshSessionRepository;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
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
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('delete@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $refreshSessionRepository->add($this->session(RefreshSessionId::generate(), $user->getId()));

        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = $this->handler(
            $userRepository,
            $refreshSessionRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new DeleteUser($actorId, $user->getId())));

        $stored = $userRepository->getById($user->getId());
        self::assertSame(UserState::DELETED, $stored?->getState());
        $retained = $userRepository->getByEmail(EmailAddress::fromString('delete@example.test'));
        self::assertInstanceOf(User::class, $retained);
        self::assertSame($user->getId(), $retained->getId());
        self::assertSame(UserState::DELETED, $retained->getState());
        self::assertTrue($refreshSessionRepository->all()[0]->isRevoked());
        self::assertSame('user.deleted', $auditEvidenceRepository->all()[0]->action()->value);
        self::assertSame($actorId, $auditEvidenceRepository->all()[0]->actorId());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(UserDeleted::class, $eventDispatcher->events()[0]);
    }

    public function test_that_a_disabled_identity_can_also_be_deleted(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('delete-disabled@example.test', UserState::DISABLED);
        $userRepository->add($user);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->handler(
            $userRepository,
            $refreshSessionRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        )->handle(
            CommandMessage::create(new DeleteUser(UserId::generate(), $user->getId()))
        );

        self::assertSame(UserState::DELETED, $userRepository->getById($user->getId())?->getState());
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
                'user_id'  => 'edb053fd-17d7-49c7-9357-7e4835de9410'
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
                'user_id'  => 'edb053fd-17d7-49c7-9357-7e4835de9410'
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
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('delete-'.$state->value.'@example.test', $state);
            $userRepository->add($user);
            $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $userRepository,
                    $refreshSessionRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    $eventDispatcher
                )->handle(
                    CommandMessage::create(new DeleteUser(UserId::generate(), $user->getId()))
                );
                self::fail('An ineligible identity was deleted.');
            } catch (StateConflict) {
                self::assertSame($state, $userRepository->getById($user->getId())?->getState());
                self::assertSame([], $auditEvidenceRepository->all());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_that_a_missing_identity_is_rejected_without_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler(
                $userRepository,
                $refreshSessionRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new DeleteUser(UserId::generate(), UserId::generate()))
            );
            self::fail('A missing identity was deleted.');
        } catch (ResourceNotFound) {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('delete@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler(
                $userRepository,
                $refreshSessionRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new DeleteUser(UserId::generate(), $user->getId()))
            );
            self::fail('A stale lifecycle state was replaced.');
        } catch (StateConflict) {
            self::assertSame(UserState::ACTIVE, $userRepository->getById($user->getId())?->getState());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryRefreshSessionRepository $refreshSessionRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher
    ): DeleteUserHandler {
        return new DeleteUserHandler(
            $userRepository,
            new SessionRevocationService($refreshSessionRepository),
            new FixedClock(new DateTimeImmutable('2026-08-20T12:00:00+00:00')),
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher,
            new InMemoryPasswordResetGrants($unitOfWork)
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
