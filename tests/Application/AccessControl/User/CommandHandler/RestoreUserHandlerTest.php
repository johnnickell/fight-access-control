<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RestoreUserHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\RestoreUser;
use Fight\AccessControl\Domain\AccessControl\User\Event\UserRestored;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
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
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = $this->handler(
            $userRepository,
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new RestoreUser(
            $actorId,
            $user->getId(),
            UserState::ACTIVE
        )));

        self::assertSame(UserState::ACTIVE, $userRepository->getById($user->getId())?->getState());
        self::assertSame([], $activationGrantRepository->all());
        self::assertSame('user.restored', $auditEvidenceRepository->all()[0]->action()->value);
        self::assertSame($actorId, $auditEvidenceRepository->all()[0]->actorId());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(UserRestored::class, $eventDispatcher->events()[0]);
        self::assertSame(UserState::ACTIVE, $eventDispatcher->events()[0]->getRestorationState());
        self::assertNull($eventDispatcher->events()[0]->getActivationDeliveryId());
    }

    public function test_that_restore_to_pending_activation_issues_fresh_activation_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $this->seedTerminalActivationGrant($activationGrantRepository, $user->getId(), $user->getEmail());
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = $this->handler(
            $userRepository,
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new RestoreUser(
            $actorId,
            $user->getId(),
            UserState::PENDING_ACTIVATION
        )));

        $restored = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $restored);
        self::assertSame(UserState::PENDING_ACTIVATION, $restored->getState());
        self::assertNull($restored->getPasswordHash());
        self::assertCount(2, $activationGrantRepository->all());
        $successor = $activationGrantRepository->all()[1];
        self::assertTrue($successor->isIssued());
        self::assertSame(ActivationOrigin::REGISTRATION, $activationGrantRepository->all()[0]->getOrigin());
        self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $successor->getOrigin());
        self::assertFalse(
            $successor->getDelivery()->getId()->equals($activationGrantRepository->all()[0]->getDelivery()->getId())
        );
        self::assertSame('ciphertext:restore-credential', $successor->getDelivery()->getEncryptedMaterial()?->reveal());
        self::assertSame('user.restored', $auditEvidenceRepository->all()[0]->action()->value);
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(UserRestored::class, $eventDispatcher->events()[0]);
        self::assertSame(UserState::PENDING_ACTIVATION, $eventDispatcher->events()[0]->getRestorationState());
        self::assertSame($successor->getDelivery()->getId(), $eventDispatcher->events()[0]->getActivationDeliveryId());
        $provider = new RecordingCredentialDeliveryProvider();
        new DeliverUserInvitationHandler(
            $activationGrantRepository->restart(),
            $unitOfWork,
            new PrefixInvitationDeliveryCipher(),
            $provider,
            new FixedClock('2026-08-22T13:00:00+00:00'),
            new InMemoryEventDispatcher(),
            $auditEvidenceRepository
        )->handle(CommandMessage::create(new DeliverUserInvitation(
            $actorId,
            $user->getId(),
            $successor->getDelivery()->getId()
        )));
        self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $provider->invocations()[0]->getActivationOrigin());
        self::assertSame('restore-credential', $provider->invocations()[0]->getCredential());
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
                'actor_id'          => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id'           => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'restoration_state' => 'active'
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
                'actor_id'               => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id'                => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'restoration_state'      => 'active',
                'activation_delivery_id' => null
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
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('restore@example.test', UserState::DELETED);
            $userRepository->add($user);
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $userRepository,
                    $activationGrantRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    $eventDispatcher
                )->handle(
                    CommandMessage::create(new RestoreUser(UserId::generate(), $user->getId(), $target))
                );
                self::fail('An unsupported restoration target was accepted.');
            } catch (StateConflict) {
                self::assertSame(UserState::DELETED, $userRepository->getById($user->getId())?->getState());
                self::assertSame([], $auditEvidenceRepository->all());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_that_a_non_deleted_identity_cannot_be_restored(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new RestoreUser(UserId::generate(), $user->getId(), UserState::ACTIVE))
            );
            self::fail('A non-deleted identity was restored.');
        } catch (StateConflict) {
            self::assertSame(UserState::ACTIVE, $userRepository->getById($user->getId())?->getState());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_missing_identity_is_rejected_without_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new RestoreUser(
                    UserId::generate(),
                    UserId::generate(),
                    UserState::ACTIVE
                ))
            );
            self::fail('A missing identity was restored.');
        } catch (ResourceNotFound) {
            self::assertSame([], $activationGrantRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_stale_lifecycle_state_cas_rolls_back(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork, replaceLifecycleStateSucceeds: false);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new RestoreUser(UserId::generate(), $user->getId(), UserState::ACTIVE))
            );
            self::fail('A stale lifecycle state was replaced.');
        } catch (StateConflict) {
            self::assertSame(UserState::DELETED, $userRepository->getById($user->getId())?->getState());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_failed_activation_successor_rolls_back_the_restoration(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('restore@example.test', UserState::DELETED);
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork, addSuccessorSucceeds: false);
        $this->seedTerminalActivationGrant($activationGrantRepository, $user->getId(), $user->getEmail());
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new RestoreUser(
                    UserId::generate(),
                    $user->getId(),
                    UserState::PENDING_ACTIVATION
                ))
            );
            self::fail('A failed activation successor was accepted.');
        } catch (StateConflict) {
            self::assertSame(UserState::DELETED, $userRepository->getById($user->getId())?->getState());
            self::assertCount(1, $activationGrantRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryActivationGrantRepository $activationGrantRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher
    ): RestoreUserHandler {
        return new RestoreUserHandler(
            $userRepository,
            $activationGrantRepository,
            new FixedCredentialGenerator('restore-credential'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2026-08-21T12:00:00+00:00'),
            $unitOfWork,
            $eventDispatcher,
            $auditEvidenceRepository
        );
    }

    private function seedTerminalActivationGrant(
        InMemoryActivationGrantRepository $activationGrantRepository,
        UserId $userId,
        EmailAddress $email
    ): void {
        $issuedAt = new DateTimeImmutable('2026-08-19T10:00:00+00:00');
        $grant = ActivationGrant::issue(
            ActivationOrigin::REGISTRATION,
            $userId,
            ActivationCredential::fromString('original-credential'),
            $issuedAt,
            $issuedAt->add(new DateInterval('P7D')),
            $email,
            'ciphertext:original-credential'
        );
        self::assertTrue($activationGrantRepository->add($grant));
        self::assertTrue($activationGrantRepository->replace(
            $grant,
            $grant->consume(new DateTimeImmutable('2026-08-19T11:00:00+00:00'))
        ));
    }
}
