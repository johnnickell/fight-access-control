<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\Exception\UserLifecycleException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestoreUserHandler::class)]
#[CoversClass(RestoreUser::class)]
#[CoversClass(UserRestored::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
final class RestoreUserHandlerTest extends TestCase
{
    public function test_that_restore_to_active_reactivates_without_issuing_activation_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = $this->handler($users, $grants, $audit, $unitOfWork, $events);
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new RestoreUser(
            $actorId,
            $user->getId(),
            UserState::ACTIVE
        )));

        self::assertSame(UserState::ACTIVE, $users->getById($user->getId())?->getState());
        self::assertSame([], $grants->all());
        self::assertSame('user.restored', $audit->all()[0]->action());
        self::assertSame($actorId->toString(), $audit->all()[0]->actorId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserRestored::class, $events->events()[0]);
        self::assertSame(UserState::ACTIVE, $events->events()[0]->getRestorationState());
        self::assertNull($events->events()[0]->getActivationDeliveryId());
    }

    public function test_that_restore_to_pending_activation_issues_fresh_activation_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $this->seedTerminalActivationGrant($grants, $user->getId(), $user->getEmail());
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = $this->handler($users, $grants, $audit, $unitOfWork, $events);
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new RestoreUser(
            $actorId,
            $user->getId(),
            UserState::PENDING_ACTIVATION
        )));

        $restored = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $restored);
        self::assertSame(UserState::PENDING_ACTIVATION, $restored->getState());
        self::assertNull($restored->getPasswordHash());
        self::assertCount(2, $grants->all());
        $successor = $grants->all()[1];
        self::assertTrue($successor->isIssued());
        self::assertSame('ciphertext:restore-credential', $successor->getDelivery()->getCiphertext());
        self::assertSame('user.restored', $audit->all()[0]->action());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserRestored::class, $events->events()[0]);
        self::assertSame(UserState::PENDING_ACTIVATION, $events->events()[0]->getRestorationState());
        self::assertSame($successor->getDelivery()->getId(), $events->events()[0]->getActivationDeliveryId());
    }

    public function test_that_the_command_and_event_round_trip(): void
    {
        self::assertSame(RestoreUser::class, RestoreUserHandler::commandRegistration());

        $actorId = UserId::generate();
        $userId = UserId::generate();
        $command = new RestoreUser($actorId, $userId, UserState::PENDING_ACTIVATION);

        self::assertEquals($command, RestoreUser::fromArray($command->toArray()));
        self::assertSame($actorId, $command->getActorId());
        self::assertSame($userId, $command->getUserId());
        self::assertSame(UserState::PENDING_ACTIVATION, $command->getRestorationState());

        $event = new UserRestored($actorId, $userId, UserState::ACTIVE);
        self::assertEquals($event, UserRestored::fromArray($event->toArray()));
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($userId, $event->getUserId());
        self::assertSame(UserState::ACTIVE, $event->getRestorationState());
        self::assertNull($event->getActivationDeliveryId());
    }

    public function test_that_missing_command_and_event_data_is_rejected(): void
    {
        foreach (['actor_id', 'user_id', 'restoration_state'] as $missing) {
            $data = [
                'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'restoration_state' => 'active',
            ];
            unset($data[$missing]);

            try {
                RestoreUser::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }
        }

        foreach (['actor_id', 'user_id', 'restoration_state', 'activation_delivery_id'] as $missing) {
            $data = [
                'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'restoration_state' => 'active',
                'activation_delivery_id' => null,
            ];
            unset($data[$missing]);

            try {
                UserRestored::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(7);
    }

    public function test_that_an_unsupported_restoration_target_is_rejected(): void
    {
        foreach ([UserState::DISABLED, UserState::DELETED] as $target) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('restore@example.test', UserState::DELETED);
            $users->add($user);
            $grants = new InMemoryActivationGrantRepository($unitOfWork);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler($users, $grants, $audit, $unitOfWork, $events)->handle(
                    CommandMessage::create(new RestoreUser(UserId::generate(), $user->getId(), $target))
                );
                self::fail('An unsupported restoration target was accepted.');
            } catch (UserLifecycleException) {
                self::assertSame(UserState::DELETED, $users->getById($user->getId())?->getState());
                self::assertSame([], $audit->all());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_that_a_non_deleted_identity_cannot_be_restored(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::ACTIVE);
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $grants, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new RestoreUser(UserId::generate(), $user->getId(), UserState::ACTIVE))
            );
            self::fail('A non-deleted identity was restored.');
        } catch (UserLifecycleException) {
            self::assertSame(UserState::ACTIVE, $users->getById($user->getId())?->getState());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_missing_identity_is_rejected_without_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $grants, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new RestoreUser(
                    UserId::generate(),
                    UserId::generate(),
                    UserState::ACTIVE
                ))
            );
            self::fail('A missing identity was restored.');
        } catch (UserLifecycleException) {
            self::assertSame([], $grants->all());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $grants, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new RestoreUser(UserId::generate(), $user->getId(), UserState::ACTIVE))
            );
            self::fail('A stale lifecycle state was replaced.');
        } catch (LogicException) {
            self::assertSame(UserState::DELETED, $users->getById($user->getId())?->getState());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_failed_activation_successor_rolls_back_the_restoration(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork, addSuccessorSucceeds: false);
        $this->seedTerminalActivationGrant($grants, $user->getId(), $user->getEmail());
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler($users, $grants, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new RestoreUser(
                    UserId::generate(),
                    $user->getId(),
                    UserState::PENDING_ACTIVATION
                ))
            );
            self::fail('A failed activation successor was accepted.');
        } catch (LogicException) {
            self::assertSame(UserState::DELETED, $users->getById($user->getId())?->getState());
            self::assertCount(1, $grants->all());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryActivationGrantRepository $grants,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): RestoreUserHandler {
        return new RestoreUserHandler(
            $users,
            $grants,
            new FixedCredentialGenerator('restore-credential'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2026-08-21T12:00:00+00:00'),
            $audit,
            $unitOfWork,
            $events
        );
    }

    private function seedTerminalActivationGrant(
        InMemoryActivationGrantRepository $grants,
        UserId $userId,
        EmailAddress $email
    ): void {
        $issuedAt = new DateTimeImmutable('2026-08-19T10:00:00+00:00');
        $grant = ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('original-credential'),
            $issuedAt,
            $issuedAt->add(new DateInterval('P7D')),
            $email,
            'ciphertext:original-credential'
        );
        self::assertTrue($grants->add($grant));
        self::assertTrue($grants->replace(
            $grant,
            $grant->consume(new DateTimeImmutable('2026-08-19T11:00:00+00:00'))
        ));
    }
}
