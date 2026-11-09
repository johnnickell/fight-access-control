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
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
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
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('disable@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $refreshSessionRepository = new InMemoryRefreshSessionRepository($unitOfWork);
        $sessionId = RefreshSessionId::generate();
        $refreshSessionRepository->add($this->session($sessionId, $user->getId()));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork, $refreshSessionRepository): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
                self::assertTrue($refreshSessionRepository->all()[0]->isRevoked());
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

        $handler->handle(CommandMessage::create(new DisableUser($actorId, $user->getId())));

        self::assertSame(UserState::DISABLED, $userRepository->getById($user->getId())?->getState());
        self::assertTrue($refreshSessionRepository->all()[0]->isRevoked());
        self::assertSame('user.disabled', $auditEvidenceRepository->all()[0]->action()->value);
        self::assertSame($actorId, $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame($user->getId(), $auditEvidenceRepository->all()[0]->getSubject()->getId());
        self::assertSame('active', $auditEvidenceRepository->all()[0]->getFacts()->toArray()['before']['state']);
        self::assertSame('disabled', $auditEvidenceRepository->all()[0]->getFacts()->toArray()['after']['state']);
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(UserDisabled::class, $eventDispatcher->events()[0]);
        self::assertSame($actorId, $eventDispatcher->events()[0]->getActorId());
        self::assertSame($user->getId(), $eventDispatcher->events()[0]->getUserId());
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
                'user_id'  => 'edb053fd-17d7-49c7-9357-7e4835de9410'
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
                'user_id'  => 'edb053fd-17d7-49c7-9357-7e4835de9410'
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
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('disable-'.$state->value.'@example.test', $state);
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
                    CommandMessage::create(new DisableUser(UserId::generate(), $user->getId()))
                );
                self::fail('A non-active identity was disabled.');
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
                CommandMessage::create(new DisableUser(UserId::generate(), UserId::generate()))
            );
            self::fail('A missing identity was disabled.');
        } catch (ResourceNotFound) {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('disable@example.test', UserState::ACTIVE);
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
                CommandMessage::create(new DisableUser(UserId::generate(), $user->getId()))
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
    ): DisableUserHandler {
        return new DisableUserHandler(
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
