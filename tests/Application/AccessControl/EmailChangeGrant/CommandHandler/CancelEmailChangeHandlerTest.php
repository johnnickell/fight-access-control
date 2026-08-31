<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\CancelEmailChangeHandler;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\CancelEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeCancelled;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception as EmailChangeException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeCancellationException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
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
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($user, $reserved));
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($grants->add(EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:change-once'
        )));
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new CancelEmailChangeHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $events
        );

        $handler->handle(CommandMessage::create(new CancelEmailChange(
            $user->getId(),
            $user->getId()
        )));

        $storedUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame('old@example.test', $storedUser->getEmail()->canonical());
        self::assertNull($storedUser->getPendingEmailChange());
        self::assertSame(2, $storedUser->getEmailChangeReservationRevision());
        self::assertTrue($grants->all()[0]->isRevoked());
        self::assertSame('2026-08-22T12:30:00+00:00', $grants->all()[0]->getRevokedAt()?->format(DATE_ATOM));
        self::assertFalse($grants->all()[0]->getDelivery()->isRecoverable());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(EmailChangeCancelled::class, $events->events()[0]);
        self::assertSame(0, $authorization->calls());
        self::assertSame([], $audit->all());
    }

    public function test_command_and_success_event_round_trip_without_confirmation_material(): void
    {
        $userId = UserId::generate();
        $command = new CancelEmailChange($userId, $userId);

        self::assertSame(CancelEmailChange::class, CancelEmailChangeHandler::commandRegistration());
        self::assertEquals($command, CancelEmailChange::fromArray($command->toArray()));
        self::assertSame($userId, $command->getActorId());
        self::assertSame($userId, $command->getUserId());

        [$handler, , , $events] = $this->fixture($userId);
        $handler->handle(CommandMessage::create($command));

        $event = $events->events()[0];
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
                'user_id' => UserId::generate()->toString(),
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
                'actor_id' => UserId::generate()->toString(),
                'user_id' => UserId::generate()->toString(),
                'email_change_grant_id' => '4c9eb57c-b493-4cc5-b47a-125ddc840baf',
                'cancelled_at' => '2026-08-22T12:30:00+00:00',
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
            $users = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
            $users->add($user);
            $reserved = clone $user;
            $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
                '2026-01-01T00:00:00+00:00'
            ));
            self::assertTrue($users->replaceEmailChangeReservation($user, $reserved));
            $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
            if ($mismatchedDelivery) {
                self::assertTrue($grants->add($this->grant($user->getId(), 'other@example.test')));
            }

            $events = new InMemoryEventDispatcher();

            try {
                $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                    new CancelEmailChange($user->getId(), $user->getId())
                ));
                self::fail('Cancellation succeeded without matching issued authority.');
            } catch (EmailChangeCancellationException) {
                self::assertSame(
                    'new@example.test',
                    $users->getById($user->getId())?->getPendingEmailChange()?->canonical()
                );
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_an_unknown_target_fails_without_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $userId = UserId::generate();

        $this->expectException(EmailChangeCancellationException::class);
        try {
            $this->handler(
                new InMemoryUserRepository($unitOfWork),
                new InMemoryEmailChangeGrantRepository($unitOfWork),
                $unitOfWork,
                $events
            )->handle(CommandMessage::create(new CancelEmailChange($userId, $userId)));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_a_lost_user_reservation_cas_leaves_authority_issued(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $state = new InMemoryUserRepositoryState();
        $seedUsers = new InMemoryUserRepository($unitOfWork, state: $state);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $seedUsers->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($seedUsers->replaceEmailChangeReservation($user, $reserved));
        $users = new InMemoryUserRepository(
            $unitOfWork,
            state: $state,
            replaceEmailChangeReservationSucceeds: false
        );
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($grants->add($this->grant($user->getId(), 'new@example.test')));
        $events = new InMemoryEventDispatcher();

        $this->expectException(LogicException::class);
        try {
            $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                new CancelEmailChange($user->getId(), $user->getId())
            ));
        } finally {
            self::assertSame(
                'new@example.test',
                $users->getById($user->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_a_lost_grant_cas_rolls_back_only_the_target_reservation_clear(): void
    {
        $userId = UserId::generate();
        [$handler, $users, $grants, $events] = $this->fixture($userId, replaceSucceeds: false);
        $other = UserFixture::withState('other@example.test', UserState::ACTIVE);
        $users->add($other);
        $reservedOther = clone $other;
        $reservedOther->requestEmailChange(EmailAddress::fromString('other-new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($other, $reservedOther));

        $this->expectException(LogicException::class);
        try {
            $handler->handle(CommandMessage::create(new CancelEmailChange($userId, $userId)));
        } finally {
            self::assertSame('new@example.test', $users->getById($userId)?->getPendingEmailChange()?->canonical());
            self::assertSame(
                'other-new@example.test',
                $users->getById($other->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_an_authorized_administrator_cancels_with_atomic_audit_without_confirming(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($target);
        $reserved = clone $target;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($target, $reserved));
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($grants->add($this->grant($target->getId(), 'new@example.test')));
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new CancelEmailChangeHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $events
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new CancelEmailChange($actorId, $target->getId())));

        self::assertSame('old@example.test', $users->getById($target->getId())->getEmail()->canonical());
        self::assertNull($users->getById($target->getId())->getPendingEmailChange());
        self::assertTrue($grants->all()[0]->isRevoked());
        self::assertFalse($grants->all()[0]->isConsumed());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($target->getId(), $authorization->lastUserId());
        self::assertSame('user.email_change_administratively_cancelled', $audit->all()[0]->action());
        self::assertSame($actorId->toString(), $audit->all()[0]->actorId());
        self::assertSame($target->getId(), $audit->all()[0]->subjectId());
        self::assertSame([], $audit->all()[0]->context());
        self::assertCount(1, $events->events());
    }

    public function test_unauthorized_administrative_cancellation_fails_before_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($target);
        $reserved = clone $target;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($target, $reserved));
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($grants->add($this->grant($target->getId(), 'new@example.test')));
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(false);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new CancelEmailChangeHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $events
        );

        $this->expectException(EmailChangeException\EmailChangeAdministrationAuthorizationException::class);
        try {
            $handler->handle(CommandMessage::create(new CancelEmailChange(
                UserId::generate(),
                $target->getId()
            )));
        } finally {
            self::assertSame(1, $authorization->calls());
            self::assertSame(
                'new@example.test',
                $users->getById($target->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_administrative_cancellation_rolls_back_when_required_audit_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($target);
        $reserved = clone $target;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($target, $reserved));
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($grants->add($this->grant($target->getId(), 'new@example.test')));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new CancelEmailChangeHandler(
            $users,
            $grants,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            $audit,
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $events
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
                $users->getById($target->getId())?->getPendingEmailChange()?->canonical()
            );
            self::assertTrue($grants->all()[0]->isIssued());
            self::assertTrue($grants->all()[0]->getDelivery()->isRecoverable());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
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
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'old@example.test',
            UserState::ACTIVE,
            1
        );
        $users->add($user);
        $reserved = clone $user;
        $reserved->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));
        self::assertTrue($users->replaceEmailChangeReservation($user, $reserved));
        $grants = new InMemoryEmailChangeGrantRepository(
            $unitOfWork,
            replaceSucceeds: $replaceSucceeds
        );
        self::assertTrue($grants->add($this->grant($userId, 'new@example.test')));
        $events = new InMemoryEventDispatcher();

        return [$this->handler($users, $grants, $unitOfWork, $events), $users, $grants, $events];
    }

    private function grant(UserId $userId, string $email): EmailChangeGrant
    {
        return EmailChangeGrant::issue(
            $userId,
            EmailChangeCredential::fromString('change-once'),
            new DateTimeImmutable('2026-08-22T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T13:00:00+00:00'),
            EmailAddress::fromString($email),
            'ciphertext:change-once'
        );
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryEmailChangeGrantRepository $grants,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): CancelEmailChangeHandler {
        return new CancelEmailChangeHandler(
            $users,
            $grants,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedClock('2026-08-22T12:30:00+00:00'),
            $events
        );
    }
}
