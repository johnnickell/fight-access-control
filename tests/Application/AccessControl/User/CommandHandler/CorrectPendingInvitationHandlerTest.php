<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\InvitationAdministrationAuthorizationException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Event\PendingInvitationCorrected;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PendingInvitationCorrectionException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service as ActivationService;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedInvitationClock;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
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
        $users = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
        $users->add($user);
        $activationGrants = new InMemoryActivationGrantRepository($unitOfWork);
        $predecessor = ActivationGrant::issue(
            $user->getId(),
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-29T12:00:00+00:00'),
            $user->getEmail(),
            'ciphertext:activate-old'
        );
        self::assertTrue($activationGrants->add($predecessor));
        $authorization = new ActivationService\FixedInvitationAdministrationAuthorization(true);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new CorrectPendingInvitationHandler(
            $users,
            $activationGrants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixInvitationDeliveryCipher(),
            new FixedInvitationClock('2026-08-22T13:00:00+00:00'),
            $events
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new CorrectPendingInvitation(
            $actorId,
            $user->getId(),
            EmailAddress::fromString('Corrected@Example.test')
        )));

        self::assertNull($users->getByEmail(EmailAddress::fromString('old@example.test')));
        self::assertSame(
            'corrected@example.test',
            $users->getById($user->getId())?->getEmail()->canonical()
        );
        self::assertCount(2, $activationGrants->all());
        self::assertTrue($activationGrants->all()[0]->isRevoked());
        self::assertTrue($activationGrants->all()[0]->matchesCredential(
            ActivationCredential::fromString('activate-old')
        ));
        self::assertFalse($activationGrants->all()[0]->isUsableAt(
            new DateTimeImmutable('2026-08-22T13:00:01+00:00')
        ));
        self::assertFalse($activationGrants->all()[0]->getDelivery()->isRetryable());
        self::assertTrue($activationGrants->all()[1]->isIssued());
        self::assertTrue($activationGrants->all()[1]->matchesCredential(
            ActivationCredential::fromString('activate-new')
        ));
        self::assertSame(
            'corrected@example.test',
            $activationGrants->all()[1]->getDelivery()->getEmail()->canonical()
        );
        self::assertSame('ciphertext:activate-new', $activationGrants->all()[1]->getDelivery()->getCiphertext());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($user->getId(), $authorization->lastUserId());
        self::assertSame('user.pending_invitation_corrected', $audit->all()[0]->action());
        self::assertSame($actorId->toString(), $audit->all()[0]->actorId());
        self::assertSame($user->getId(), $audit->all()[0]->userId());
        self::assertSame([], $audit->all()[0]->context());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PendingInvitationCorrected::class, $events->events()[0]);
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
                'user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'email' => 'corrected@example.test',
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
                'actor_id' => 'c3bc62b6-b87c-4371-b585-c47a059878f1',
                'user_id' => 'edb053fd-17d7-49c7-9357-7e4835de9410',
                'email' => 'corrected@example.test',
                'activation_delivery_id' => '6cc07528-cfb1-4cb7-a28f-805c5a1d0083',
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
        $users = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        $authorization = new ActivationService\FixedInvitationAdministrationAuthorization(false);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->expectException(InvitationAdministrationAuthorizationException::class);
        try {
            $this->handler($users, $grants, $authorization, $audit, $unitOfWork, $events)->handle(
                CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $user->getId(),
                    EmailAddress::fromString('corrected@example.test')
                ))
            );
        } finally {
            self::assertSame('old@example.test', $users->getById($user->getId())?->getEmail()->canonical());
            self::assertSame([], $grants->all());
            self::assertSame([], $audit->all());
            self::assertSame(1, $authorization->calls());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_active_and_same_address_users_cannot_be_corrected(): void
    {
        foreach ([UserState::ACTIVE, UserState::PENDING_ACTIVATION] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
            if ($state === UserState::ACTIVE) {
                $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
            }

            $users->add($user);
            $grants = new InMemoryActivationGrantRepository($unitOfWork);
            self::assertTrue($grants->add($this->grant($user)));
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $users,
                    $grants,
                    new ActivationService\FixedInvitationAdministrationAuthorization(true),
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $user->getId(),
                    EmailAddress::fromString(
                        $state === UserState::ACTIVE ? 'corrected@example.test' : 'old@example.test'
                    )
                )));
                self::fail('An ineligible pending invitation was corrected.');
            } catch (PendingInvitationCorrectionException) {
                self::assertSame('old@example.test', $users->getById($user->getId())?->getEmail()->canonical());
                self::assertTrue($grants->all()[0]->isIssued());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_canonical_and_live_email_change_reservations_block_the_destination(): void
    {
        foreach ([false, true] as $liveReservation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $target = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
            $reserved = UserFixture::withState('claimed@example.test', UserState::ACTIVE);
            $users->add($target);
            $users->add($reserved);
            $destination = 'claimed@example.test';
            if ($liveReservation) {
                $destination = 'reserved@example.test';
                $replacement = clone $reserved;
                $replacement->requestEmailChange(EmailAddress::fromString($destination));
                self::assertTrue($users->replaceEmailChangeReservation($reserved, $replacement));
            }

            $grants = new InMemoryActivationGrantRepository($unitOfWork);
            self::assertTrue($grants->add($this->grant($target)));
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $users,
                    $grants,
                    new ActivationService\FixedInvitationAdministrationAuthorization(true),
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $target->getId(),
                    EmailAddress::fromString($destination)
                )));
                self::fail('A claimed invitation destination was accepted.');
            } catch (LogicException) {
                self::assertSame('old@example.test', $users->getById($target->getId())?->getEmail()->canonical());
                self::assertTrue($grants->all()[0]->isIssued());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_missing_terminal_or_mismatched_predecessor_is_rejected_without_mutation(): void
    {
        foreach (['missing', 'terminal', 'mismatched'] as $condition) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
            $users->add($user);
            $grants = new InMemoryActivationGrantRepository($unitOfWork);
            if ($condition !== 'missing') {
                $grant = $this->grant($user);
                if ($condition === 'mismatched') {
                    $grant = $this->grant($user, 'different@example.test');
                }

                self::assertTrue($grants->add($grant));
                if ($condition === 'terminal') {
                    self::assertTrue($grants->replace(
                        $grant,
                        $grant->revoke(new DateTimeImmutable('2026-08-22T13:00:00+00:00'))
                    ));
                }
            }

            $events = new InMemoryEventDispatcher();

            try {
                $this->handler(
                    $users,
                    $grants,
                    new ActivationService\FixedInvitationAdministrationAuthorization(true),
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    $events
                )->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $user->getId(),
                    EmailAddress::fromString('corrected@example.test')
                )));
                self::fail('Invalid predecessor authority was accepted.');
            } catch (PendingInvitationCorrectionException) {
                self::assertSame('old@example.test', $users->getById($user->getId())?->getEmail()->canonical());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_a_stale_predecessor_cas_rolls_back_the_email_replacement(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork, replaceWithSuccessorSucceeds: false);
        self::assertTrue($grants->add($this->grant($user)));
        $events = new InMemoryEventDispatcher();

        $this->expectException(LogicException::class);
        try {
            $this->handler(
                $users,
                $grants,
                new ActivationService\FixedInvitationAdministrationAuthorization(true),
                new InMemoryAuditEvidenceRepository($unitOfWork),
                $unitOfWork,
                $events
            )->handle(CommandMessage::create(new CorrectPendingInvitation(
                UserId::generate(),
                $user->getId(),
                EmailAddress::fromString('corrected@example.test')
            )));
        } finally {
            self::assertSame('old@example.test', $users->getById($user->getId())?->getEmail()->canonical());
            self::assertCount(1, $grants->all());
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_late_audit_failure_rolls_back_email_grants_delivery_and_evidence(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($grants->add($this->grant($user)));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();

        $this->expectException(RuntimeException::class);
        try {
            $this->handler(
                $users,
                $grants,
                new ActivationService\FixedInvitationAdministrationAuthorization(true),
                $audit,
                $unitOfWork,
                $events
            )->handle(CommandMessage::create(new CorrectPendingInvitation(
                UserId::generate(),
                $user->getId(),
                EmailAddress::fromString('corrected@example.test')
            )));
        } finally {
            self::assertSame('old@example.test', $users->getById($user->getId())?->getEmail()->canonical());
            self::assertCount(1, $grants->all());
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertTrue($grants->all()[0]->getDelivery()->isRetryable());
            self::assertSame('ciphertext:activate-old', $grants->all()[0]->getDelivery()->getCiphertext());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame(
                'The audit persistence write failed.',
                $events->events()[0]->getErrorMessage()
            );
        }
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryActivationGrantRepository $grants,
        ActivationService\FixedInvitationAdministrationAuthorization $authorization,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): CorrectPendingInvitationHandler {
        return new CorrectPendingInvitationHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixInvitationDeliveryCipher(),
            new FixedInvitationClock('2026-08-22T13:00:00+00:00'),
            $events
        );
    }

    private function grant(User $user, string $email = 'old@example.test'): ActivationGrant
    {
        return ActivationGrant::issue(
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
        $users = new InMemoryUserRepository($unitOfWork);
        $user = User::invite(UserId::generate(), EmailAddress::fromString('old@example.test'));
        $users->add($user);
        $grants = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($grants->add($this->grant($user)));
        $events = new InMemoryEventDispatcher();
        $this->handler(
            $users,
            $grants,
            new ActivationService\FixedInvitationAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $events
        )->handle(CommandMessage::create(new CorrectPendingInvitation(
            UserId::generate(),
            $user->getId(),
            EmailAddress::fromString('corrected@example.test')
        )));

        $event = $events->events()[0];
        self::assertInstanceOf(PendingInvitationCorrected::class, $event);

        return $event;
    }
}
