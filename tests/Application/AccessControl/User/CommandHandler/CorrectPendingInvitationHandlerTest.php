<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingInvitationCorrected;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service as ActivationService;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CorrectPendingInvitationHandler::class)]
#[CoversClass(CorrectPendingInvitation::class)]
#[CoversClass(PendingInvitationCorrected::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(AuditEvidence::class)]
#[CoversClass(User::class)]
final class CorrectPendingInvitationHandlerTest extends TestCase
{
    public function test_authorized_correction_atomically_replaces_identity_authority_delivery_and_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('old@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $predecessor = ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $user->getId(),
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-29T12:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:activate-old'
        );
        self::assertTrue($activationGrantRepository->add($predecessor));
        $authorization = new ActivationService\FixedInvitationAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = new CorrectPendingInvitationHandler(
            $userRepository,
            $activationGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2026-08-22T13:00:00+00:00'),
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new CorrectPendingInvitation(
            $actorId,
            $user->getId(),
            EmailAddress::fromString('Corrected@Example.test')
        )));

        self::assertNull($userRepository->getByEmail(EmailAddress::fromString('old@example.test')));
        self::assertSame(
            'corrected@example.test',
            $userRepository->getById($user->getId())?->getEmail()->canonical()
        );
        self::assertCount(2, $activationGrantRepository->all());
        self::assertTrue($activationGrantRepository->all()[0]->isRevoked());
        self::assertTrue($activationGrantRepository->all()[0]->matchesCredential(
            ActivationCredential::fromString('activate-old')
        ));
        self::assertFalse($activationGrantRepository->all()[0]->isUsableAt(
            new DateTimeImmutable('2026-08-22T13:00:01+00:00')
        ));
        self::assertFalse($activationGrantRepository->all()[0]->getDelivery()->isRetryable());
        self::assertTrue($activationGrantRepository->all()[1]->isIssued());
        self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $activationGrantRepository->all()[1]->getOrigin());
        self::assertTrue($activationGrantRepository->all()[1]->matchesCredential(
            ActivationCredential::fromString('activate-new')
        ));
        self::assertSame(
            'corrected@example.test',
            $activationGrantRepository->all()[1]->getDelivery()->getEmail()->canonical()
        );
        self::assertSame(
            'ciphertext:activate-new',
            $activationGrantRepository->all()[1]->getDelivery()->getEncryptedMaterial()?->reveal()
        );
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($user->getId(), $authorization->lastUserId());
        self::assertSame('user.pending_invitation_corrected', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($actorId->toString(), $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame($user->getId(), $auditEvidenceRepository->all()[0]->subjectId());
        self::assertSame([], $auditEvidenceRepository->all()[0]->context());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(PendingInvitationCorrected::class, $eventDispatcher->events()[0]);
    }

    public function test_the_command_and_success_event_round_trip_without_secret_material(): void
    {
        $actorId = UserId::generate();
        $userId = UserId::generate();
        $command = new CorrectPendingInvitation(
            $actorId,
            $userId,
            EmailAddress::fromString('corrected@example.test')
        );

        self::assertSame(CorrectPendingInvitation::class, CorrectPendingInvitationHandler::commandRegistration());
        self::assertEquals($command, CorrectPendingInvitation::fromArray($command->toArray()));
        self::assertSame($actorId, $command->getActorId());
        self::assertSame($userId, $command->getUserId());
        self::assertSame('corrected@example.test', $command->getEmail()->canonical());

        $deliveryId = $this->successfulEvent()->getActivationDeliveryId();
        $event = new PendingInvitationCorrected(
            $actorId,
            $userId,
            EmailAddress::fromString('corrected@example.test'),
            $deliveryId
        );
        self::assertEquals($event, PendingInvitationCorrected::fromArray($event->toArray()));
        self::assertSame($actorId, $event->getActorId());
        self::assertSame($userId, $event->getUserId());
        self::assertSame('corrected@example.test', $event->getEmail()->canonical());
        self::assertSame($deliveryId, $event->getActivationDeliveryId());
        self::assertArrayNotHasKey('credential', $event->toArray());
        self::assertArrayNotHasKey('ciphertext', $event->toArray());
    }

    public function test_missing_command_and_event_data_is_rejected(): void
    {
        foreach (['actor_id', 'user_id', 'email'] as $missing) {
            $data = [
                'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id'  => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'email'    => 'corrected@example.test'
            ];
            unset($data[$missing]);

            try {
                CorrectPendingInvitation::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }
        }

        foreach (['actor_id', 'user_id', 'email', 'activation_delivery_id'] as $missing) {
            $data = [
                'actor_id'               => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id'                => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'email'                  => 'corrected@example.test',
                'activation_delivery_id' => '6cc07528-cfb1-4cb7-a28f-805c5a1d0083'
            ];
            unset($data[$missing]);

            try {
                PendingInvitationCorrected::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(7);
    }

    public function test_unauthorized_correction_fails_before_read_or_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('old@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $authorization = new ActivationService\FixedInvitationAdministrationAuthorization(false);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(PermissionDenied::class);
        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                $authorization,
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(
                CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $user->getId(),
                    EmailAddress::fromString('corrected@example.test')
                ))
            );
        } finally {
            self::assertSame('old@example.test', $userRepository->getById($user->getId())?->getEmail()->canonical());
            self::assertSame([], $activationGrantRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertSame(1, $authorization->calls());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_active_and_same_address_users_cannot_be_corrected(): void
    {
        foreach ([UserState::ACTIVE, UserState::PENDING_ACTIVATION] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = User::invite(
                UserId::generate(),
                EmailAddress::fromString('old@example.test'),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );
            if ($state === UserState::ACTIVE) {
                $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
            }

            $userRepository->add($user);
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            self::assertTrue($activationGrantRepository->add($this->grant($user)));
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $userRepository,
                    $activationGrantRepository,
                    new ActivationService\FixedInvitationAdministrationAuthorization(true),
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $eventDispatcher
                )->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $user->getId(),
                    EmailAddress::fromString(
                        $state === UserState::ACTIVE ? 'corrected@example.test' : 'old@example.test'
                    )
                )));
                self::fail('An ineligible pending invitation was corrected.');
            } catch (StateConflict) {
                self::assertSame(
                    'old@example.test',
                    $userRepository->getById($user->getId())?->getEmail()->canonical()
                );
                self::assertTrue($activationGrantRepository->all()[0]->isIssued());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_canonical_and_live_email_change_reservations_block_the_destination(): void
    {
        foreach ([false, true] as $liveReservation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $target = User::invite(
                UserId::generate(),
                EmailAddress::fromString('old@example.test'),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );
            $reserved = UserFixture::withState('claimed@example.test', UserState::ACTIVE);
            $userRepository->add($target);
            $userRepository->add($reserved);
            $destination = 'claimed@example.test';
            if ($liveReservation) {
                $destination = 'reserved@example.test';
                $replacement = clone $reserved;
                $replacement->requestEmailChange(
                    EmailAddress::fromString($destination),
                    new DateTimeImmutable('2026-01-01T00:00:00+00:00')
                );
                self::assertTrue($userRepository->replaceEmailChangeReservation($reserved, $replacement));
            }

            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            self::assertTrue($activationGrantRepository->add($this->grant($target)));
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $userRepository,
                    $activationGrantRepository,
                    new ActivationService\FixedInvitationAdministrationAuthorization(true),
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $eventDispatcher
                )->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $target->getId(),
                    EmailAddress::fromString($destination)
                )));
                self::fail('A claimed invitation destination was accepted.');
            } catch (StateConflict) {
                self::assertSame(
                    'old@example.test',
                    $userRepository->getById($target->getId())?->getEmail()->canonical()
                );
                self::assertTrue($activationGrantRepository->all()[0]->isIssued());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_missing_terminal_or_mismatched_predecessor_is_rejected_without_mutation(): void
    {
        foreach (['missing', 'terminal', 'mismatched'] as $condition) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = User::invite(
                UserId::generate(),
                EmailAddress::fromString('old@example.test'),
                new DateTimeImmutable('2026-01-01T00:00:00+00:00')
            );
            $userRepository->add($user);
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            if ($condition !== 'missing') {
                $grant = $this->grant($user);
                if ($condition === 'mismatched') {
                    $grant = $this->grant($user, 'different@example.test');
                }

                self::assertTrue($activationGrantRepository->add($grant));
                if ($condition === 'terminal') {
                    self::assertTrue($activationGrantRepository->replace(
                        $grant,
                        $grant->revoke(new DateTimeImmutable('2026-08-22T13:00:00+00:00'))
                    ));
                }
            }

            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $userRepository,
                    $activationGrantRepository,
                    new ActivationService\FixedInvitationAdministrationAuthorization(true),
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $eventDispatcher
                )->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $user->getId(),
                    EmailAddress::fromString('corrected@example.test')
                )));
                self::fail('Invalid predecessor authority was accepted.');
            } catch (StateConflict) {
                self::assertSame(
                    'old@example.test',
                    $userRepository->getById($user->getId())?->getEmail()->canonical()
                );
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_a_stale_predecessor_cas_rolls_back_the_email_replacement(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('old@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository(
            $unitOfWork,
            replaceWithSuccessorSucceeds: false
        );
        self::assertTrue($activationGrantRepository->add($this->grant($user)));
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(StateConflict::class);
        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                new ActivationService\FixedInvitationAdministrationAuthorization(true),
                new InMemoryAuditEvidenceRepository($unitOfWork),
                $unitOfWork,
                $eventDispatcher
            )->handle(CommandMessage::create(new CorrectPendingInvitation(
                UserId::generate(),
                $user->getId(),
                EmailAddress::fromString('corrected@example.test')
            )));
        } finally {
            self::assertSame('old@example.test', $userRepository->getById($user->getId())?->getEmail()->canonical());
            self::assertCount(1, $activationGrantRepository->all());
            self::assertTrue($activationGrantRepository->all()[0]->isIssued());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_late_audit_failure_rolls_back_email_grants_delivery_and_evidence(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('old@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($this->grant($user)));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(RuntimeException::class);
        try {
            $this->handler(
                $userRepository,
                $activationGrantRepository,
                new ActivationService\FixedInvitationAdministrationAuthorization(true),
                $auditEvidenceRepository,
                $unitOfWork,
                $eventDispatcher
            )->handle(CommandMessage::create(new CorrectPendingInvitation(
                UserId::generate(),
                $user->getId(),
                EmailAddress::fromString('corrected@example.test')
            )));
        } finally {
            self::assertSame('old@example.test', $userRepository->getById($user->getId())?->getEmail()->canonical());
            self::assertCount(1, $activationGrantRepository->all());
            self::assertTrue($activationGrantRepository->all()[0]->isIssued());
            self::assertTrue($activationGrantRepository->all()[0]->getDelivery()->isRetryable());
            self::assertSame(
                'ciphertext:activate-old',
                $activationGrantRepository->all()[0]->getDelivery()->getEncryptedMaterial()?->reveal()
            );
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            self::assertSame(
                'Pending invitation correction failed.',
                $eventDispatcher->events()[0]->getErrorMessage()
            );
        }
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryActivationGrantRepository $activationGrantRepository,
        ActivationService\FixedInvitationAdministrationAuthorization $authorization,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher
    ): CorrectPendingInvitationHandler {
        return new CorrectPendingInvitationHandler(
            $userRepository,
            $activationGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2026-08-22T13:00:00+00:00'),
            $eventDispatcher
        );
    }

    private function grant(User $user, string $email = 'old@example.test'): ActivationGrant
    {
        return ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $user->getId(),
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-29T12:00:00+00:00'),
            EmailAddress::fromString($email),
            'ciphertext:activate-old'
        );
    }

    private function successfulEvent(): PendingInvitationCorrected
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(
            UserId::generate(),
            EmailAddress::fromString('old@example.test'),
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );
        $userRepository->add($user);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($this->grant($user)));
        $eventDispatcher = new InMemoryEventDispatcher();
        $this->handler(
            $userRepository,
            $activationGrantRepository,
            new ActivationService\FixedInvitationAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $eventDispatcher
        )->handle(CommandMessage::create(new CorrectPendingInvitation(
            UserId::generate(),
            $user->getId(),
            EmailAddress::fromString('corrected@example.test')
        )));

        $event = $eventDispatcher->events()[0];
        self::assertInstanceOf(PendingInvitationCorrected::class, $event);

        return $event;
    }
}
