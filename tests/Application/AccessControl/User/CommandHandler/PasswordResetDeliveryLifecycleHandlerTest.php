<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ConfirmPasswordResetDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\User\Command\ConfirmPasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\User\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryPasswordResetDeliveryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConfirmPasswordResetDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ConfirmPasswordResetDelivery::class)]
#[CoversClass(ExpirePasswordResetDelivery::class)]
#[CoversClass(PasswordResetDeliveryConfirmed::class)]
#[CoversClass(PasswordResetDeliveryExpired::class)]
final class PasswordResetDeliveryLifecycleHandlerTest extends TestCase
{
    public function test_delayed_commands_for_a_superseded_generation_do_not_invalidate_fresh_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $userId = UserId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7a001');
        $deliveryA = PasswordResetDelivery::create(
            PasswordResetDeliveryId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7b001'),
            $userId,
            'alice@example.test',
            'ciphertext-a',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
        $deliveryB = PasswordResetDelivery::create(
            PasswordResetDeliveryId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7b002'),
            $userId,
            'alice@example.test',
            'ciphertext-b',
            new DateTimeImmutable('2026-08-20T13:15:00+00:00')
        );
        $repository->add($deliveryA);
        $repository->replace($deliveryA, $deliveryA->invalidate(), $deliveryB);

        $events = new InMemoryEventDispatcher();

        new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events)->handle(
            CommandMessage::create(new ConfirmPasswordResetDelivery(
                'password-reset-transport',
                $userId,
                $deliveryA->getId(),
                new DateTimeImmutable('2026-08-20T12:20:00+00:00')
            ))
        );
        new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events)->handle(
            CommandMessage::create(new ExpirePasswordResetDelivery(
                'password-reset-expiry',
                $userId,
                $deliveryA->getId(),
                new DateTimeImmutable('2026-08-20T14:00:00+00:00')
            ))
        );

        $freshDelivery = $repository->getById($deliveryB->getId());
        self::assertInstanceOf(PasswordResetDelivery::class, $freshDelivery);
        self::assertSame('ciphertext-b', $freshDelivery->getCiphertext());
        self::assertTrue($freshDelivery->isRecoverable());
        self::assertCount(0, $events->events());
    }

    public function test_that_confirmation_atomically_destroys_ciphertext_before_publishing_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $delivery = $this->delivery();
        $repository->add($delivery);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events);
        $command = new ConfirmPasswordResetDelivery(
            'password-reset-transport',
            $delivery->getUserId(),
            $delivery->getId(),
            new DateTimeImmutable('2026-08-20T12:10:00+00:00')
        );

        $handler->handle(CommandMessage::create($command));

        self::assertSame(ConfirmPasswordResetDelivery::class, $handler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertFalse($repository->getByUserId($delivery->getUserId())?->isRecoverable());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetDeliveryConfirmed::class, $events->events()[0]);
    }

    public function test_that_concurrent_terminal_transitions_emit_no_duplicate_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetDeliveryRepository(
            $unitOfWork,
            replaceInvalidatedSucceeds: false
        );
        $delivery = $this->delivery();
        $repository->add($delivery);
        $events = new InMemoryEventDispatcher();

        new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events)->handle(
            CommandMessage::create(new ConfirmPasswordResetDelivery(
                'password-reset-transport',
                $delivery->getUserId(),
                $delivery->getId(),
                new DateTimeImmutable('2026-08-20T12:10:00+00:00')
            ))
        );
        new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events)->handle(
            CommandMessage::create(new ExpirePasswordResetDelivery(
                'password-reset-expiry',
                $delivery->getUserId(),
                $delivery->getId(),
                new DateTimeImmutable('2026-08-20T13:00:00+00:00')
            ))
        );

        self::assertSame($delivery, $repository->getByUserId($delivery->getUserId()));
        self::assertSame('ciphertext', $delivery->getCiphertext());
        self::assertCount(0, $events->events());
    }

    public function test_that_confirmation_is_safe_for_missing_or_already_invalidated_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $delivery = $this->delivery();
        $repository->add($delivery->invalidate());
        $events = new InMemoryEventDispatcher();
        $handler = new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'password-reset-transport',
            $delivery->getUserId(),
            $delivery->getId(),
            new DateTimeImmutable('2026-08-20T12:10:00+00:00')
        )));
        $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'password-reset-transport',
            UserId::generate(),
            $delivery->getId(),
            new DateTimeImmutable('2026-08-20T12:10:00+00:00')
        )));

        self::assertSame(2, $unitOfWork->transactions);
        self::assertCount(0, $events->events());
    }

    public function test_that_expiry_destroys_ciphertext_only_at_the_terminal_boundary(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetDeliveryRepository($unitOfWork);
        $delivery = $this->delivery();
        $repository->add($delivery);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $delivery->getUserId(),
            $delivery->getId(),
            new DateTimeImmutable('2026-08-20T12:59:59+00:00')
        )));
        self::assertTrue($repository->getByUserId($delivery->getUserId())?->isRecoverable());
        self::assertCount(0, $events->events());

        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $delivery->getUserId(),
            $delivery->getId(),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        )));

        self::assertSame(ExpirePasswordResetDelivery::class, $handler::commandRegistration());
        self::assertFalse($repository->getByUserId($delivery->getUserId())->isRecoverable());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(PasswordResetDeliveryExpired::class, $events->events()[0]);

        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $delivery->getUserId(),
            $delivery->getId(),
            new DateTimeImmutable('2026-08-20T13:00:01+00:00')
        )));
        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            UserId::generate(),
            PasswordResetDeliveryId::generate(),
            new DateTimeImmutable('2026-08-20T13:00:01+00:00')
        )));

        self::assertSame(4, $unitOfWork->transactions);
        self::assertCount(1, $events->events());
    }

    public function test_that_repository_read_failures_are_rethrown_and_publish_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new ExpirePasswordResetDeliveryHandler(
            $this->failingRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
                'password-reset-expiry',
                UserId::generate(),
                PasswordResetDeliveryId::generate(),
                new DateTimeImmutable('2026-08-20T13:00:00+00:00')
            )));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_confirmation_read_failures_are_rethrown_and_publish_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new ConfirmPasswordResetDeliveryHandler(
            $this->failingRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
                'password-reset-transport',
                UserId::generate(),
                PasswordResetDeliveryId::generate(),
                new DateTimeImmutable('2026-08-20T12:10:00+00:00')
            )));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_commands_and_events_round_trip_without_secret_material(): void
    {
        $userId = UserId::generate();
        $passwordResetDeliveryId = PasswordResetDeliveryId::generate();
        $occurredAt = new DateTimeImmutable('2026-08-20T13:00:00+00:00');
        $command = new ConfirmPasswordResetDelivery(
            'password-reset-transport',
            $userId,
            $passwordResetDeliveryId,
            $occurredAt
        );
        $expiryCommand = new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $userId,
            $passwordResetDeliveryId,
            $occurredAt
        );
        $event = new PasswordResetDeliveryConfirmed(
            'password-reset-transport',
            $userId,
            $passwordResetDeliveryId,
            $occurredAt
        );
        $expiryEvent = new PasswordResetDeliveryExpired(
            'password-reset-expiry',
            $userId,
            $passwordResetDeliveryId,
            $occurredAt
        );

        self::assertEquals($command, ConfirmPasswordResetDelivery::fromArray($command->toArray()));
        self::assertEquals($expiryCommand, ExpirePasswordResetDelivery::fromArray($expiryCommand->toArray()));
        self::assertEquals($event, PasswordResetDeliveryConfirmed::fromArray($event->toArray()));
        self::assertEquals($expiryEvent, PasswordResetDeliveryExpired::fromArray($expiryEvent->toArray()));
        self::assertSame('password-reset-transport', $event->getActorId());
        self::assertSame($userId, $event->getUserId());
        self::assertSame($passwordResetDeliveryId, $event->getPasswordResetDeliveryId());
        self::assertSame($occurredAt, $event->getOccurredAt());
        self::assertSame('password-reset-expiry', $expiryEvent->getActorId());
        self::assertSame($userId, $expiryEvent->getUserId());
        self::assertSame($passwordResetDeliveryId, $expiryEvent->getPasswordResetDeliveryId());
        self::assertSame($occurredAt, $expiryEvent->getOccurredAt());
        self::assertStringNotContainsString('ciphertext', implode('|', $event->toArray()));
    }

    public function test_that_lifecycle_messages_reject_missing_required_data(): void
    {
        foreach (
            [
            ConfirmPasswordResetDelivery::class,
            ExpirePasswordResetDelivery::class,
            PasswordResetDeliveryConfirmed::class,
            PasswordResetDeliveryExpired::class,
            ] as $messageClass
        ) {
            try {
                $messageClass::fromArray([]);
                self::fail($messageClass.' accepted missing data.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function delivery(): PasswordResetDelivery
    {
        return PasswordResetDelivery::create(
            PasswordResetDeliveryId::generate(),
            UserId::generate(),
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        );
    }

    private function failingRepository(): PasswordResetDeliveryRepository
    {
        return new class implements PasswordResetDeliveryRepository {
            public function getById(PasswordResetDeliveryId $passwordResetDeliveryId): ?PasswordResetDelivery
            {
                throw new RuntimeException('Password-reset delivery persistence failed.');
            }

            public function getByUserId(UserId $userId): ?PasswordResetDelivery
            {
                throw new RuntimeException('Password-reset delivery persistence failed.');
            }

            public function add(PasswordResetDelivery $passwordResetDelivery): bool
            {
                return false;
            }

            public function appendAfterTerminal(
                PasswordResetDelivery $terminalPredecessor,
                PasswordResetDelivery $replacement
            ): bool {
                return false;
            }

            public function replaceInvalidated(
                PasswordResetDelivery $predecessor,
                PasswordResetDelivery $invalidatedPasswordResetDelivery
            ): bool {
                return false;
            }

            public function replace(
                PasswordResetDelivery $predecessor,
                PasswordResetDelivery $invalidatedPredecessor,
                PasswordResetDelivery $replacement
            ): bool {
                return false;
            }
        };
    }
}
