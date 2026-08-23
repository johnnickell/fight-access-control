<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception as EmailChangeException;
use Fight\AccessControl\Domain\AccessControl\User\Exception\EmailChangeRequestException;
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
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\FixedEmailChangeCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\PrefixEmailChangeDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestEmailChangeHandler::class)]
#[CoversClass(RequestEmailChange::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(EmailChangeRequested::class)]
#[CoversClass(User::class)]
final class RequestEmailChangeHandlerTest extends TestCase
{
    public function test_an_active_user_can_request_an_unrelated_generation_after_every_terminal_outcome(): void
    {
        foreach (['confirmed', 'cancelled', 'expired'] as $outcome) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
            $users->add($user);
            $reservedUser = clone $user;
            $reservedUser->requestEmailChange(EmailAddress::fromString('first@example.test'), new DateTimeImmutable(
                '2026-01-01T00:00:00+00:00'
            ));
            self::assertTrue($users->replaceEmailChangeReservation($user, $reservedUser));
            $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $predecessor = EmailChangeGrant::issue(
                $user->getId(),
                EmailChangeCredential::fromString('predecessor-'.$outcome),
                new DateTimeImmutable('2026-08-22T10:00:00+00:00'),
                new DateTimeImmutable('2026-08-22T11:00:00+00:00'),
                EmailAddress::fromString('first@example.test'),
                'ciphertext:predecessor-'.$outcome
            );
            self::assertTrue($grants->add($predecessor));
            if ($outcome === 'confirmed') {
                $terminalUser = clone $reservedUser;
                $terminalUser->confirmEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                $terminalUser->advanceAuthenticationAuthorityRevision();
                self::assertTrue($users->replaceEmailChangeConfirmation($reservedUser, $terminalUser));
                $terminalPredecessor = $predecessor->consume(
                    new DateTimeImmutable('2026-08-22T10:30:00+00:00')
                );
            } elseif ($outcome === 'cancelled') {
                $terminalUser = clone $reservedUser;
                $terminalUser->cancelEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::assertTrue($users->replaceEmailChangeReservation($reservedUser, $terminalUser));
                $terminalPredecessor = $predecessor->revoke(
                    new DateTimeImmutable('2026-08-22T10:30:00+00:00')
                );
            } else {
                $terminalUser = clone $reservedUser;
                $terminalUser->expireEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::assertTrue($users->replaceEmailChangeReservation($reservedUser, $terminalUser));
                $terminalPredecessor = $predecessor->expireAt(
                    new DateTimeImmutable('2026-08-22T11:00:00+00:00')
                );
            }

            self::assertTrue($grants->replace($predecessor, $terminalPredecessor));
            $events = new InMemoryEventDispatcher();

            $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                new RequestEmailChange(
                    $user->getId(),
                    $user->getId(),
                    EmailAddress::fromString('successor-'.$outcome.'@example.test')
                )
            ));

            self::assertCount(2, $grants->all());
            self::assertSame($terminalPredecessor, $grants->all()[0]);
            self::assertFalse($grants->all()[0]->isUsableAt(
                new DateTimeImmutable('2026-08-22T12:00:00+00:00')
            ));
            self::assertFalse($grants->all()[0]->getDelivery()->isRecoverable());
            self::assertTrue($grants->all()[1]->isIssued());
            self::assertNotSame($grants->all()[0]->getId()->toString(), $grants->all()[1]->getId()->toString());
            self::assertNotSame(
                $grants->all()[0]->getDelivery()->getId()->toString(),
                $grants->all()[1]->getDelivery()->getId()->toString()
            );
            self::assertNotSame($grants->all()[0]->getCredentialHash(), $grants->all()[1]->getCredentialHash());
            self::assertSame(
                'e360e565220f2dfb032b70e01acf5ebb17ab3e47a79d8cb4a825e7ac0a6aa4ea',
                $grants->all()[1]->getCredentialHash()
            );
            self::assertSame(
                'successor-'.$outcome.'@example.test',
                $grants->all()[1]->getDelivery()->getEmail()->canonical()
            );
        }
    }

    public function test_an_active_owner_reserves_a_destination_and_records_confirmation_work_atomically(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($user);
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new RequestEmailChangeHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $events
        );

        $handler->handle(CommandMessage::create(new RequestEmailChange(
            $user->getId(),
            $user->getId(),
            EmailAddress::fromString('New@Example.test')
        )));

        $storedUser = $users->getById($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame('old@example.test', $storedUser->getEmail()->canonical());
        self::assertSame('new@example.test', $storedUser->getPendingEmailChange()->canonical());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $grants->all());
        self::assertSame(
            'e360e565220f2dfb032b70e01acf5ebb17ab3e47a79d8cb4a825e7ac0a6aa4ea',
            $grants->all()[0]->getCredentialHash()
        );
        self::assertSame('email_change', $grants->all()[0]->purpose());
        self::assertSame('2026-08-22T13:00:00+00:00', $grants->all()[0]->getExpiresAt()->format(DATE_ATOM));
        self::assertSame('new@example.test', $grants->all()[0]->getDelivery()->getEmail()->canonical());
        self::assertSame('ciphertext:change-once', $grants->all()[0]->getDelivery()->getCiphertext());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(EmailChangeRequested::class, $events->events()[0]);
        self::assertSame(0, $authorization->calls());
        self::assertSame([], $audit->all());
    }

    public function test_a_successor_cas_loss_rolls_back_the_new_destination_reservation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($user);
        $grants = new InMemoryEmailChangeGrantRepository(
            $unitOfWork,
            appendAfterTerminalSucceeds: false
        );
        $issued = EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('predecessor'),
            new DateTimeImmutable('2026-08-22T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T11:00:00+00:00'),
            EmailAddress::fromString('previous@example.test'),
            'ciphertext:predecessor'
        );
        self::assertTrue($grants->add($issued));
        $terminal = $issued->revoke(new DateTimeImmutable('2026-08-22T10:30:00+00:00'));
        self::assertTrue($grants->replace($issued, $terminal));
        $events = new InMemoryEventDispatcher();

        $this->expectException(LogicException::class);
        try {
            $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                new RequestEmailChange(
                    $user->getId(),
                    $user->getId(),
                    EmailAddress::fromString('successor@example.test')
                )
            ));
        } finally {
            self::assertNull($users->getById($user->getId())?->getPendingEmailChange());
            self::assertSame([$terminal], $grants->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_the_command_and_success_event_round_trip_without_secret_material(): void
    {
        $userId = UserId::generate();
        $command = new RequestEmailChange($userId, $userId, EmailAddress::fromString('new@example.test'));

        self::assertSame(RequestEmailChange::class, RequestEmailChangeHandler::commandRegistration());
        self::assertEquals($command, RequestEmailChange::fromArray($command->toArray()));
        self::assertSame($userId, $command->getActorId());
        self::assertSame($userId, $command->getUserId());
        self::assertSame('new@example.test', $command->getEmail()->canonical());

        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $users->add(UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'old@example.test',
            UserState::ACTIVE,
            1
        ));
        $events = new InMemoryEventDispatcher();
        $this->handler(
            $users,
            new InMemoryEmailChangeGrantRepository($unitOfWork),
            $unitOfWork,
            $events
        )->handle(CommandMessage::create($command));

        $event = $events->events()[0];
        self::assertInstanceOf(EmailChangeRequested::class, $event);
        self::assertEquals($event, EmailChangeRequested::fromArray($event->toArray()));
        self::assertSame($userId, $event->getActorId());
        self::assertSame($userId, $event->getUserId());
        self::assertSame('new@example.test', $event->getEmail()->canonical());
        self::assertSame('2026-08-22T12:00:00+00:00', $event->getIssuedAt()->format(DATE_ATOM));
        self::assertSame(
            $event->getEmailChangeDeliveryId()->toString(),
            $event->toArray()['email_change_delivery_id']
        );
        self::assertArrayNotHasKey('credential', $event->toArray());
        self::assertArrayNotHasKey('ciphertext', $event->toArray());
    }

    public function test_missing_command_and_event_data_is_rejected(): void
    {
        foreach (['actor_id', 'user_id', 'email'] as $missing) {
            $data = [
                'actor_id' => UserId::generate()->toString(),
                'user_id' => UserId::generate()->toString(),
                'email' => 'new@example.test',
            ];
            unset($data[$missing]);

            try {
                RequestEmailChange::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }
        }

        foreach (['actor_id', 'user_id', 'email_change_delivery_id', 'email', 'issued_at'] as $missing) {
            $data = [
                'actor_id' => UserId::generate()->toString(),
                'user_id' => UserId::generate()->toString(),
                'email_change_delivery_id' => '4c9eb57c-b493-4cc5-b47a-125ddc840baf',
                'email' => 'new@example.test',
                'issued_at' => '2026-08-22T12:00:00+00:00',
            ];
            unset($data[$missing]);

            try {
                EmailChangeRequested::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(8);
    }

    public function test_unknown_and_inactive_owners_fail_without_durable_work(): void
    {
        foreach ([null, UserState::DISABLED] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $userId = UserId::generate();
            if ($state instanceof UserState) {
                $users->add(UserFixture::withIdAndAuthenticationVersion(
                    $userId,
                    'old@example.test',
                    $state,
                    1
                ));
            }

            $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();

            try {
                $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                    new RequestEmailChange($userId, $userId, EmailAddress::fromString('new@example.test'))
                ));
                self::fail('An ineligible owner requested an email change.');
            } catch (EmailChangeRequestException) {
                self::assertSame([], $grants->all());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_a_canonical_email_or_live_destination_cannot_be_reserved_by_another_user(): void
    {
        foreach ([false, true] as $liveReservation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $users = new InMemoryUserRepository($unitOfWork);
            $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $first = UserFixture::withState('first@example.test', UserState::ACTIVE);
            $second = UserFixture::withState('second@example.test', UserState::ACTIVE);
            $users->add($first);
            $users->add($second);

            $destination = 'first@example.test';
            if ($liveReservation) {
                $destination = 'reserved@example.test';
                $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                    new RequestEmailChange($first->getId(), $first->getId(), EmailAddress::fromString($destination))
                ));
            }

            try {
                $this->handler($users, $grants, $unitOfWork, $events)->handle(CommandMessage::create(
                    new RequestEmailChange($second->getId(), $second->getId(), EmailAddress::fromString($destination))
                ));
                self::fail('A reserved destination was claimed twice.');
            } catch (LogicException) {
                self::assertNull($users->getById($second->getId())?->getPendingEmailChange());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[array_key_last($events->events())]);
            }
        }
    }

    public function test_a_grant_generation_race_rolls_back_the_destination_reservation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($user);
        $events = new InMemoryEventDispatcher();

        $this->expectException(LogicException::class);
        try {
            $this->handler(
                $users,
                new InMemoryEmailChangeGrantRepository($unitOfWork, addSucceeds: false),
                $unitOfWork,
                $events
            )->handle(CommandMessage::create(new RequestEmailChange(
                $user->getId(),
                $user->getId(),
                EmailAddress::fromString('new@example.test')
            )));
        } finally {
            self::assertNull($users->getById($user->getId())?->getPendingEmailChange());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_an_authorized_administrator_requests_the_same_confirmed_journey_with_atomic_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($target);
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($audit, $unitOfWork): void {
            self::assertCount(1, $audit->all());
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new RequestEmailChangeHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $events
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new RequestEmailChange(
            $actorId,
            $target->getId(),
            EmailAddress::fromString('new@example.test')
        )));

        self::assertSame('old@example.test', $users->getById($target->getId())->getEmail()->canonical());
        self::assertSame(
            'new@example.test',
            $users->getById($target->getId())->getPendingEmailChange()?->canonical()
        );
        self::assertTrue($grants->all()[0]->isIssued());
        self::assertTrue($grants->all()[0]->getDelivery()->isRecoverable());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($target->getId(), $authorization->lastUserId());
        self::assertSame('user.email_change_administratively_requested', $audit->all()[0]->action());
        self::assertSame($actorId->toString(), $audit->all()[0]->actorId());
        self::assertSame($target->getId(), $audit->all()[0]->userId());
        self::assertSame([], $audit->all()[0]->context());
        self::assertCount(1, $events->events());
    }

    public function test_unauthorized_administrative_initiation_fails_before_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($target);
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(false);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestEmailChangeHandler(
            $users,
            $grants,
            $authorization,
            $audit,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $events
        );
        $actorId = UserId::generate();

        $this->expectException(EmailChangeException\EmailChangeAdministrationAuthorizationException::class);
        try {
            $handler->handle(CommandMessage::create(new RequestEmailChange(
                $actorId,
                $target->getId(),
                EmailAddress::fromString('new@example.test')
            )));
        } finally {
            self::assertSame(1, $authorization->calls());
            self::assertNull($users->getById($target->getId())?->getPendingEmailChange());
            self::assertSame([], $grants->all());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_administrative_initiation_rolls_back_when_required_audit_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $users = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $users->add($target);
        $grants = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new RequestEmailChangeHandler(
            $users,
            $grants,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            $audit,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new RequestEmailChange(
                UserId::generate(),
                $target->getId(),
                EmailAddress::fromString('new@example.test')
            )));
        } finally {
            self::assertNull($users->getById($target->getId())?->getPendingEmailChange());
            self::assertSame([], $grants->all());
            self::assertSame([], $audit->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    private function handler(
        InMemoryUserRepository $users,
        InMemoryEmailChangeGrantRepository $grants,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): RequestEmailChangeHandler {
        return new RequestEmailChangeHandler(
            $users,
            $grants,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $events
        );
    }
}
