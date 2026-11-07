<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\CommandHandler\EnableUserHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\EnableUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserEnabled;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnableUserHandler::class)]
#[CoversClass(EnableUser::class)]
#[CoversClass(UserEnabled::class)]
#[CoversClass(AuditEvidence::class)]
final class EnableUserHandlerTest extends TestCase
{
    public function test_that_enable_atomically_reactivates_and_audits_without_session_restoration(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('enable@example.test', UserState::DISABLED);
        $userRepository->add($user);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = new EnableUserHandler(
            $userRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher,
            new FixedClock('2026-01-01T00:00:00+00:00')
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new EnableUser($actorId, $user->getId())));

        self::assertSame(UserState::ACTIVE, $userRepository->getById($user->getId())?->getState());
        self::assertSame('user.enabled', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($actorId->toString(), $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame($user->getId(), $auditEvidenceRepository->all()[0]->subjectId());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(UserEnabled::class, $eventDispatcher->events()[0]);
    }

    public function test_that_the_command_and_event_round_trip(): void
    {
        self::assertSame(EnableUser::class, EnableUserHandler::commandRegistration());

        $actorId = UserId::generate();
        $userId = UserId::generate();
        $command = new EnableUser($actorId, $userId);

        self::assertEquals($command, EnableUser::fromArray($command->toArray()));

        $event = new UserEnabled($actorId, $userId);
        self::assertEquals($event, UserEnabled::fromArray($event->toArray()));
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
                EnableUser::fromArray($data);
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
                UserEnabled::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(4);
    }

    public function test_that_a_non_disabled_identity_cannot_be_enabled(): void
    {
        foreach ([UserState::PENDING_ACTIVATION, UserState::ACTIVE, UserState::DELETED] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('enable-'.$state->value.'@example.test', $state);
            $userRepository->add($user);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                new EnableUserHandler(
                    $userRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    $eventDispatcher,
                    new FixedClock('2026-01-01T00:00:00+00:00')
                )->handle(
                    CommandMessage::create(new EnableUser(UserId::generate(), $user->getId()))
                );
                self::fail('A non-disabled identity was enabled.');
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
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            new EnableUserHandler(
                $userRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher,
                new FixedClock('2026-01-01T00:00:00+00:00')
            )->handle(
                CommandMessage::create(new EnableUser(UserId::generate(), UserId::generate()))
            );
            self::fail('A missing identity was enabled.');
        } catch (ResourceNotFound) {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('enable@example.test', UserState::DISABLED);
        $userRepository->add($user);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            new EnableUserHandler(
                $userRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher,
                new FixedClock('2026-01-01T00:00:00+00:00')
            )->handle(
                CommandMessage::create(new EnableUser(UserId::generate(), $user->getId()))
            );
            self::fail('A stale lifecycle state was replaced.');
        } catch (StateConflict) {
            self::assertSame(UserState::DISABLED, $userRepository->getById($user->getId())?->getState());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }
}
