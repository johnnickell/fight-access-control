<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\RequestEmailChangeHandler;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\RequestEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
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
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\FixedEmailChangeCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\PrefixEmailChangeDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
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
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
            $userRepository->add($user);
            $reservedUser = clone $user;
            $reservedUser->requestEmailChange(EmailAddress::fromString('first@example.test'), new DateTimeImmutable(
                '2026-01-01T00:00:00+00:00'
            ));
            self::assertTrue($userRepository->replaceEmailChangeReservation($user, $reservedUser));
            $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $predecessor = EmailChangeGrant::issue(
                $user->getId(),
                EmailChangeCredential::fromString('predecessor-'.$outcome),
                new DateTimeImmutable('2026-08-22T10:00:00+00:00'),
                new DateTimeImmutable('2026-08-22T11:00:00+00:00'),
                EmailAddress::fromString('first@example.test'),
                'ciphertext:predecessor-'.$outcome,
                $reservedUser->getEmailChangeReservationRevision()
            );
            self::assertTrue($emailChangeGrantRepository->add($predecessor));
            if ($outcome === 'confirmed') {
                $terminalUser = clone $reservedUser;
                $terminalUser->confirmEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::assertTrue($userRepository->replaceEmailChangeConfirmation($reservedUser, $terminalUser));
                $terminalPredecessor = $predecessor->consume(
                    new DateTimeImmutable('2026-08-22T10:30:00+00:00')
                );
            } elseif ($outcome === 'cancelled') {
                $terminalUser = clone $reservedUser;
                $terminalUser->cancelEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::assertTrue($userRepository->replaceEmailChangeReservation($reservedUser, $terminalUser));
                $terminalPredecessor = $predecessor->revoke(
                    new DateTimeImmutable('2026-08-22T10:30:00+00:00')
                );
            } else {
                $terminalUser = clone $reservedUser;
                $terminalUser->expireEmailChange(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
                self::assertTrue($userRepository->replaceEmailChangeReservation($reservedUser, $terminalUser));
                $terminalPredecessor = $predecessor->expireAt(
                    new DateTimeImmutable('2026-08-22T11:00:00+00:00')
                );
            }

            self::assertTrue($emailChangeGrantRepository->replace($predecessor, $terminalPredecessor));
            $eventDispatcher = new InMemoryEventDispatcher();

            $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                CommandMessage::create(
                    new RequestEmailChange(
                        $user->getId(),
                        $user->getId(),
                        EmailAddress::fromString('successor-'.$outcome.'@example.test')
                    )
                )
            );

            self::assertCount(2, $emailChangeGrantRepository->all());
            self::assertSame($terminalPredecessor, $emailChangeGrantRepository->all()[0]);
            self::assertFalse($emailChangeGrantRepository->all()[0]->isUsableAt(
                new DateTimeImmutable('2026-08-22T12:00:00+00:00')
            ));
            self::assertFalse($emailChangeGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
            self::assertTrue($emailChangeGrantRepository->all()[1]->isIssued());
            self::assertNotSame(
                $emailChangeGrantRepository->all()[0]->getId()->toString(),
                $emailChangeGrantRepository->all()[1]->getId()->toString()
            );
            self::assertNotSame(
                $emailChangeGrantRepository->all()[0]->getDelivery()->getId()->toString(),
                $emailChangeGrantRepository->all()[1]->getDelivery()->getId()->toString()
            );
            self::assertNotSame(
                $emailChangeGrantRepository->all()[0]->getCredentialHash(),
                $emailChangeGrantRepository->all()[1]->getCredentialHash()
            );
            self::assertSame(
                'e360e565220f2dfb032b70e01acf5ebb17ab3e47a79d8cb4a825e7ac0a6aa4ea',
                $emailChangeGrantRepository->all()[1]->getCredentialHash()
            );
            self::assertSame(
                'successor-'.$outcome.'@example.test',
                $emailChangeGrantRepository->all()[1]->getDelivery()->getEmail()->canonical()
            );
        }
    }

    public function test_an_active_owner_reserves_a_destination_and_records_confirmation_work_atomically(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new RequestEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $eventDispatcher
        );

        $handler->handle(CommandMessage::create(new RequestEmailChange(
            $user->getId(),
            $user->getId(),
            EmailAddress::fromString('New@Example.test')
        )));

        $storedUser = $userRepository->getById($user->getId());
        self::assertInstanceOf(User::class, $storedUser);
        self::assertSame('old@example.test', $storedUser->getEmail()->canonical());
        self::assertSame('new@example.test', $storedUser->getPendingEmailChange()->canonical());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $emailChangeGrantRepository->all());
        self::assertSame(
            'e360e565220f2dfb032b70e01acf5ebb17ab3e47a79d8cb4a825e7ac0a6aa4ea',
            $emailChangeGrantRepository->all()[0]->getCredentialHash()
        );
        self::assertSame('email_change', $emailChangeGrantRepository->all()[0]->purpose());
        self::assertSame(
            '2026-08-22T13:00:00+00:00',
            $emailChangeGrantRepository->all()[0]->getExpiresAt()->format(DATE_ATOM)
        );
        self::assertSame(
            'new@example.test',
            $emailChangeGrantRepository->all()[0]->getDelivery()->getEmail()->canonical()
        );
        self::assertSame(
            'ciphertext:change-once',
            $emailChangeGrantRepository->all()[0]->getDelivery()->getEncryptedMaterial()?->reveal()
        );
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(EmailChangeRequested::class, $eventDispatcher->events()[0]);
        self::assertSame(0, $authorization->calls());
        self::assertSame([], $auditEvidenceRepository->all());
    }

    public function test_a_successor_cas_loss_rolls_back_the_new_destination_reservation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository(
            $unitOfWork,
            appendAfterTerminalSucceeds: false
        );
        $issued = EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('predecessor'),
            new DateTimeImmutable('2026-08-22T10:00:00+00:00'),
            new DateTimeImmutable('2026-08-22T11:00:00+00:00'),
            EmailAddress::fromString('previous@example.test'),
            'ciphertext:predecessor',
            1
        );
        self::assertTrue($emailChangeGrantRepository->add($issued));
        $terminal = $issued->revoke(new DateTimeImmutable('2026-08-22T10:30:00+00:00'));
        self::assertTrue($emailChangeGrantRepository->replace($issued, $terminal));
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(StateConflict::class);
        try {
            $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                CommandMessage::create(
                    new RequestEmailChange(
                        $user->getId(),
                        $user->getId(),
                        EmailAddress::fromString('successor@example.test')
                    )
                )
            );
        } finally {
            self::assertNull($userRepository->getById($user->getId())?->getPendingEmailChange());
            self::assertSame([$terminal], $emailChangeGrantRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
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
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $userRepository->add(UserFixture::withIdAndAuthenticationVersion(
            $userId,
            'old@example.test',
            UserState::ACTIVE,
            1
        ));
        $eventDispatcher = new InMemoryEventDispatcher();
        $this->handler(
            $userRepository,
            new InMemoryEmailChangeGrantRepository($unitOfWork),
            $unitOfWork,
            $eventDispatcher
        )->handle(CommandMessage::create($command));

        $event = $eventDispatcher->events()[0];
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
                'user_id'  => UserId::generate()->toString(),
                'email'    => 'new@example.test'
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
                'actor_id'                 => UserId::generate()->toString(),
                'user_id'                  => UserId::generate()->toString(),
                'email_change_delivery_id' => '4c9eb57c-b493-4cc5-b47a-125ddc840baf',
                'email'                    => 'new@example.test',
                'issued_at'                => '2026-08-22T12:00:00+00:00'
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
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $userId = UserId::generate();
            if ($state instanceof UserState) {
                $userRepository->add(UserFixture::withIdAndAuthenticationVersion(
                    $userId,
                    'old@example.test',
                    $state,
                    1
                ));
            }

            $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();

            try {
                $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                    CommandMessage::create(
                        new RequestEmailChange($userId, $userId, EmailAddress::fromString('new@example.test'))
                    )
                );
                self::fail('An ineligible owner requested an email change.');
            } catch (ResourceNotFound | StateConflict $exception) {
                self::assertInstanceOf($state === null ? ResourceNotFound::class : StateConflict::class, $exception);
                self::assertSame([], $emailChangeGrantRepository->all());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_a_canonical_email_or_live_destination_cannot_be_reserved_by_another_user(): void
    {
        foreach ([false, true] as $liveReservation) {
            $unitOfWork = new InMemoryUnitOfWork();
            $userRepository = new InMemoryUserRepository($unitOfWork);
            $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();
            $first = UserFixture::withState('first@example.test', UserState::ACTIVE);
            $second = UserFixture::withState('second@example.test', UserState::ACTIVE);
            $userRepository->add($first);
            $userRepository->add($second);

            $destination = 'first@example.test';
            if ($liveReservation) {
                $destination = 'reserved@example.test';
                $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                    CommandMessage::create(
                        new RequestEmailChange($first->getId(), $first->getId(), EmailAddress::fromString($destination))
                    )
                );
            }

            try {
                $this->handler($userRepository, $emailChangeGrantRepository, $unitOfWork, $eventDispatcher)->handle(
                    CommandMessage::create(
                        new RequestEmailChange(
                            $second->getId(),
                            $second->getId(),
                            EmailAddress::fromString($destination)
                        )
                    )
                );
                self::fail('A reserved destination was claimed twice.');
            } catch (StateConflict) {
                self::assertNull($userRepository->getById($second->getId())?->getPendingEmailChange());
                self::assertInstanceOf(
                    CommandFailedEvent::class,
                    $eventDispatcher->events()[array_key_last($eventDispatcher->events())]
                );
            }
        }
    }

    public function test_a_grant_generation_race_rolls_back_the_destination_reservation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($user);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->expectException(StateConflict::class);
        try {
            $this->handler(
                $userRepository,
                new InMemoryEmailChangeGrantRepository($unitOfWork, addSucceeds: false),
                $unitOfWork,
                $eventDispatcher
            )->handle(CommandMessage::create(new RequestEmailChange(
                $user->getId(),
                $user->getId(),
                EmailAddress::fromString('new@example.test')
            )));
        } finally {
            self::assertNull($userRepository->getById($user->getId())?->getPendingEmailChange());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_an_authorized_administrator_requests_the_same_confirmed_journey_with_atomic_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($target);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher(
            static function () use ($auditEvidenceRepository, $unitOfWork): void {
                self::assertCount(1, $auditEvidenceRepository->all());
                self::assertTrue($unitOfWork->transactionCompleted);
            }
        );
        $handler = new RequestEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $handler->handle(CommandMessage::create(new RequestEmailChange(
            $actorId,
            $target->getId(),
            EmailAddress::fromString('new@example.test')
        )));

        self::assertSame('old@example.test', $userRepository->getById($target->getId())->getEmail()->canonical());
        self::assertSame(
            'new@example.test',
            $userRepository->getById($target->getId())->getPendingEmailChange()?->canonical()
        );
        self::assertTrue($emailChangeGrantRepository->all()[0]->isIssued());
        self::assertTrue($emailChangeGrantRepository->all()[0]->getDelivery()->hasRecoverableMaterial());
        self::assertSame(1, $authorization->calls());
        self::assertSame($actorId, $authorization->lastActorId());
        self::assertSame($target->getId(), $authorization->lastUserId());
        self::assertSame('user.email_change_administratively_requested', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($actorId->toString(), $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame($target->getId(), $auditEvidenceRepository->all()[0]->subjectId());
        self::assertSame([], $auditEvidenceRepository->all()[0]->context());
        self::assertCount(1, $eventDispatcher->events());
    }

    public function test_unauthorized_administrative_initiation_fails_before_mutation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($target);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $authorization = new EmailChangeService\FixedEmailChangeAdministrationAuthorization(false);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RequestEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            $authorization,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $eventDispatcher
        );
        $actorId = UserId::generate();

        $this->expectException(PermissionDenied::class);
        try {
            $handler->handle(CommandMessage::create(new RequestEmailChange(
                $actorId,
                $target->getId(),
                EmailAddress::fromString('new@example.test')
            )));
        } finally {
            self::assertSame(1, $authorization->calls());
            self::assertNull($userRepository->getById($target->getId())?->getPendingEmailChange());
            self::assertSame([], $emailChangeGrantRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_administrative_initiation_rolls_back_when_required_audit_fails(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $userRepository = new InMemoryUserRepository($unitOfWork);
        $target = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $userRepository->add($target);
        $emailChangeGrantRepository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RequestEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $eventDispatcher
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new RequestEmailChange(
                UserId::generate(),
                $target->getId(),
                EmailAddress::fromString('new@example.test')
            )));
        } finally {
            self::assertNull($userRepository->getById($target->getId())?->getPendingEmailChange());
            self::assertSame([], $emailChangeGrantRepository->all());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    private function handler(
        InMemoryUserRepository $userRepository,
        InMemoryEmailChangeGrantRepository $emailChangeGrantRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $eventDispatcher
    ): RequestEmailChangeHandler {
        return new RequestEmailChangeHandler(
            $userRepository,
            $emailChangeGrantRepository,
            new EmailChangeService\FixedEmailChangeAdministrationAuthorization(true),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedEmailChangeCredentialGenerator('change-once'),
            new PrefixEmailChangeDeliveryCipher(),
            new FixedClock('2026-08-22T12:00:00+00:00'),
            $eventDispatcher
        );
    }
}
