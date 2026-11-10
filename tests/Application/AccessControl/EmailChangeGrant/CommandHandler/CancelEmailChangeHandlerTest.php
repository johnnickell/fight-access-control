<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\CancelEmailChangeHandler;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditUserRevisions;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\CancelEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeCancelled;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\PermissionDenied;
use Fight\Common\Application\Error\Exception\ResourceNotFound;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service as EmailChangeService;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepositoryState;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(CancelEmailChangeHandler::class)]
#[CoversClass(CancelEmailChange::class)]
#[CoversClass(EmailChangeCancelled::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(User::class)]
final class CancelEmailChangeHandlerTest extends TestCase
{
    public function test_the_owner_atomically_cancels_the_issued_change_without_changing_canonical_identity(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reserved));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add(EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once',
            $reserved->getEmailChangeReservationRevision()
        )));
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new CancelEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $eventDispatcher
        );

        $handler->handle(CommandMessage::create(new CancelEmailChange(
            $user->getId(),
            $user->getId()
        )));

        $storedUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame('old@example.test', $storedUser->getEmail()->canonical());
        self::assertNull($storedUser->getPendingEmailChange());
        self::assertSame(2, $storedUser->getEmailChangeReservationRevision());
        self::assertTrue($emailChangeGrantRepository->all()[0]->isRevoked());
        self::assertSame(
            '2026-08-22T12:30:00+00:00',
            $emailChangeGrantRepository->all()[0]->getRevokedAt()?->format(DATE_ATOM)
        );
        self::assertFalse($emailChangeGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(EmailChangeCancelled::class, $eventDispatcher->events()[0]);
        self::assertSame(0, $authorization->calls());
        self::assertSame([], $auditEvidenceRepository->all());
    }

    public function test_command_and_success_event_round_trip_without_confirmation_material(): void
    {
        $userId = UserId::generate();
        $command = new CancelEmailChange($userId, $userId);

        self::assertSame(CancelEmailChange::class, CancelEmailChangeHandler::commandRegistration());
        self::assertEquals($command, CancelEmailChange::fromArray($command->toArray()));
        self::assertSame($userId, $command->getActorId());
        self::assertSame($userId, $command->getUserId());

        [$handler, , , $eventDispatcher] = $this->fixture($userId);
        $handler->handle(CommandMessage::create($command));

        $event = $eventDispatcher->events()[0];
        self::assertInstanceOf(EmailChangeCancelled::class, $event);
        self::assertEquals($event, EmailChangeCancelled::fromArray($event->toArray()));
        self::assertSame($userId, $event->getActorId());
        self::assertSame($userId, $event->getUserId());
        self::assertSame('2026-08-22T12:30:00+00:00', $event->getCancelledAt()->format(DATE_ATOM));
        self::assertSame($event->getEmailChangeGrantId()->toString(), $event->toArray()['email_change_grant_id']);
        self::assertArrayNotHasKey('credential', $event->toArray());
        self::assertArrayNotHasKey('ciphertext', $event->toArray());
    }

    public function test_missing_command_and_event_data_is_rejected(): void
    {
        foreach (['actor_id', 'user_id'] as $missing) {
            $data = [
                'actor_id' => UserId::generate()->toString(),
                'user_id'  => UserId::generate()->toString()
            ];
            unset($data[$missing]);

            try {
                CancelEmailChange::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }
        }

        foreach (['actor_id', 'user_id', 'email_change_grant_id', 'cancelled_at'] as $missing) {
            $data = [
                'actor_id'              => UserId::generate()->toString(),
                'user_id'               => UserId::generate()->toString(),
                'email_change_grant_id' => '4c9eb57c-b493-4cc5-b47a-125ddc840baf',
                'cancelled_at'          => '2026-08-22T12:30:00+00:00'
            ];
            unset($data[$missing]);

            try {
                EmailChangeCancelled::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(6);
    }

    public function test_missing_or_mismatched_authority_leaves_the_reservation_unchanged(): void
    {
        foreach ([false, true] as $mismatchedDelivery) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
            $userRepository->add($user);
            $reserved = clone $user;
            $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
                '2026-01-01T00:00:00+00:00'
            ));
            self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reserved));
            $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
            if ($mismatchedDelivery) {
                self::assertTrue($emailChangeGrantRepository->add($this->grant($user->getId(), 'other@example.test')));
            }

            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                    CommandMessage::create(
                        new CancelEmailChange($user->getId(), $user->getId())
                    )
                );
                self::fail('Cancellation succeeded without matching issued authority.');
            } catch (StateConflict | LogicException $exception) {
                self::assertInstanceOf($mismatchedDelivery ? LogicException::class : StateConflict::class, $exception);
                self::assertSame(
                    'new@example.test',
                    $userRepository->getById($user->getId())?->getPendingEmailChange()?->canonical()
                );
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_an_unknown_target_fails_without_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $eventDispatcher = new InMemoryEventDispatcher();
        $userId = UserId::generate();

        $this->expectException(ResourceNotFound::class);
        try {
            $this->handler(
                new InMemoryUserRepository($unitOfWork),
                new InMemoryEmailChangeGrantRepository($unitOfWork),
                $unitOfWork,
                $eventDispatcher
            )->handle(CommandMessage::create(new CancelEmailChange($userId, $userId)));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_a_lost_user_reservation_cas_leaves_authority_issued(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $state = new InMemoryUserRepositoryState();
        $seedUserRepository = new InMemoryUserRepository($unitOfWork, state: $state);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $seedUserRepository->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($seedUserRepository->replaceEmailChangeReservation($user, $reserved));
        $userRepository = new InMemoryUserRepository(
            $unitOfWork,
            state: $state,
            replaceEmailChangeReservationSucceeds: false
        );
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add($this->grant($user->getId(), 'new@example.test')));
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(StateConflict::class);
        try {
            $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                CommandMessage::create(
                    new CancelEmailChange($user->getId(), $user->getId())
                )
            );
        } finally {
            self::assertSame(
                'new@example.test',
                $userRepository->getById($user->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_a_lost_grant_cas_rolls_back_only_the_target_reservation_clear(): void
    {
        $userId = UserId::generate();
        [$handler, $userRepository, $emailChangeGrantRepository, $eventDispatcher] = $this->fixture(
            $userId,
            replaceSucceeds: false
        );
        $other = UserFixture::withState('other@example.test', UserState::ACTIVE);
        $userRepository->add($other);
        $reservedOther = clone $other;
        $reservedOther->requestEmailChange(EmailAddress::fromString('other-new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($userRepository->replaceEmailChangeReservation($other, $reservedOther));

        $this->expectException(StateConflict::class);
        try {
            $handler->handle(CommandMessage::create(new CancelEmailChange($userId, $userId)));
        } finally {
            self::assertSame(
                'new@example.test',
                $userRepository->getById($userId)?->getPendingEmailChange()?->canonical()
            );
            self::assertSame(
                'other-new@example.test',
                $userRepository->getById($other->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_an_authorized_administrator_cancels_with_atomic_audit_without_confirming(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($target);
        $reserved = clone $target;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($userRepository->replaceEmailChangeReservation($target, $reserved));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add($this->grant($target->getId(), 'new@example.test')));
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = new CancelEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new CancelEmailChange($actorId, $target->getId())));

        self::assertSame('old@example.test', $userRepository->getById($target->getId())->getEmail()->canonical());
        self::assertNull($userRepository->getById($target->getId())->getPendingEmailChange());
        self::assertTrue($emailChangeGrantRepository->all()[0]->isRevoked());
        self::assertFalse($emailChangeGrantRepository->all()[0]->isConsumed());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($target->getId(), $authorization->lastUserId());
        $evidence = $auditEvidenceRepository->all()[0];
        self::assertSame('user.email_change_administratively_cancelled', $evidence->action()->value);
        self::assertSame($actorId, $evidence->actorId());
        self::assertSame($target->getId(), $evidence->getSubject()->getId());
        $facts = $evidence->getFacts();
        self::assertInstanceOf(AuditUserOutcome::class, $facts);
        self::assertEquals(AuditUserRevisions::fromUser($reserved), $facts->getBefore());
        self::assertEquals(
            AuditUserRevisions::fromUser($userRepository->getById($target->getId()))->toArray(),
            $facts->toArray()['after']
        );
        self::assertEquals(AuditGrantState::fromGrant($emailChangeGrantRepository->all()[0]), $facts->getGrantAfter());
        self::assertCount(1, $eventDispatcher->events());
    }

    public function test_unauthorized_administrative_cancellation_fails_before_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($target);
        $reserved = clone $target;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($userRepository->replaceEmailChangeReservation($target, $reserved));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add($this->grant($target->getId(), 'new@example.test')));
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(false);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new CancelEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $eventDispatcher
        );

        $this->expectException(PermissionDenied::class);
        try {
            $handler->handle(CommandMessage::create(new CancelEmailChange(
                UserId::generate(),
                $target->getId()
            )));
        } finally {
            self::assertSame(1, $authorization->calls());
            self::assertSame(
                'new@example.test',
                $userRepository->getById($target->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_administrative_cancellation_rolls_back_when_required_audit_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($target);
        $reserved = clone $target;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($userRepository->replaceEmailChangeReservation($target, $reserved));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($emailChangeGrantRepository->add($this->grant($target->getId(), 'new@example.test')));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new CancelEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $eventDispatcher
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new CancelEmailChange(
                UserId::generate(),
                $target->getId()
            )));
        } finally {
            self::assertSame(
                'new@example.test',
                $userRepository->getById($target->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
            self::assertTrue($emailChangeGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    /**
     * @return array{CancelEmailChangeHandler, InMemoryUserRepository, InMemoryEmailChangeGrantRepository,
     *     InMemoryEventDispatcher}
     */
    private function fixture(
        UserId $userId,
        bool $replaceSucceeds = true
    ): array {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'old@example.test',
            UserState::ACTIVE,
            1
        );
        $userRepository->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reserved));
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository(
            $unitOfWork,
            replaceSucceeds: $replaceSucceeds
        );
        self::assertTrue($emailChangeGrantRepository->add($this->grant($userId, 'new@example.test')));
        $eventDispatcher = new InMemoryEventDispatcher();

        return [
            $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher),
            $userRepository,
            $emailChangeGrantRepository,
            $eventDispatcher
        ];
    }

    private function grant(UserId $userId, string $email): EmailChangeGrant
    {
        return EmailChangeGrant::issue(
            $userId,
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString($email),
            'ciphertext:change-once',
            1
        );
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryEmailChangeGrantRepository $emailChangeGrantRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher
    ): CancelEmailChangeHandler {
        return new CancelEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $eventDispatcher
        );
    }
}
