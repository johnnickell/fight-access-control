<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ConfirmPasswordResetDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ConfirmPasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ConfirmPasswordResetDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ConfirmPasswordResetDelivery::class)]
#[CoversClass(ExpirePasswordResetDelivery::class)]
#[CoversClass(PasswordResetDeliveryConfirmed::class)]
#[CoversClass(PasswordResetDeliveryExpired::class)]
#[CoversClass(PasswordResetGrant::class)]
final class PasswordResetDeliveryLifecycleHandlerTest extends TestCase
{
    public function test_confirmation_atomically_destroys_ciphertext_before_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $grant = $this->grant();
        $repository->add($grant);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'password-reset-transport',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-20T12:10:00+00:00')
        )));

        self::assertSame(ConfirmPasswordResetDelivery::class, $handler::commandRegistration());
        self::assertFalse($repository->getById($grant->getId())->getDelivery()->isRecoverable());
        self::assertInstanceOf(PasswordResetDeliveryConfirmed::class, $events->events()[0]);
    }

    public function test_expiry_only_terminalizes_at_the_boundary(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $grant = $this->grant();
        $repository->add($grant);
        $events = new InMemoryEventDispatcher();
        $handler = new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-20T12:59:59+00:00')
        )));
        self::assertTrue($repository->getById($grant->getId())?->getDelivery()->isRecoverable());

        $handler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'password-reset-expiry',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        )));

        self::assertSame(ExpirePasswordResetDelivery::class, $handler::commandRegistration());
        self::assertFalse($repository->getById($grant->getId())->getDelivery()->isRecoverable());
        self::assertInstanceOf(PasswordResetDeliveryExpired::class, $events->events()[0]);
    }

    public function test_missing_mismatched_terminal_and_lost_cas_callbacks_are_no_ops(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork, replaceSucceeds: false);
        $grant = $this->grant();
        $repository->add($grant);
        $events = new InMemoryEventDispatcher();
        $handler = new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'transport',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable()
        )));
        $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'transport',
            UserId::generate(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable()
        )));
        $handler->handle(CommandMessage::create(new ConfirmPasswordResetDelivery(
            'transport',
            $grant->getUserId(),
            PasswordResetDeliveryId::generate(),
            new DateTimeImmutable()
        )));
        $expiryHandler = new ExpirePasswordResetDeliveryHandler($repository, $unitOfWork, $events);
        $expiryHandler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'expiry',
            UserId::generate(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        )));
        $expiryHandler->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
            'expiry',
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00')
        )));

        self::assertSame($grant, $repository->getById($grant->getId()));
        self::assertSame([], $events->events());
    }

    public function test_stale_callbacks_cannot_mutate_a_newer_generation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        $old = $this->grant('old');
        $new = $this->grant('new');
        $repository->add($old);
        self::assertTrue($repository->replaceWithSuccessor(
            $old,
            $old->revoke(new DateTimeImmutable('2026-08-20T12:10:00+00:00')),
            $new
        ));
        $events = new InMemoryEventDispatcher();

        new ConfirmPasswordResetDeliveryHandler($repository, $unitOfWork, $events)->handle(
            CommandMessage::create(new ConfirmPasswordResetDelivery(
                'transport',
                $old->getUserId(),
                $old->getDelivery()->getId(),
                new DateTimeImmutable('2026-08-20T12:20:00+00:00')
            ))
        );

        self::assertSame('ciphertext:new', $repository->getById($new->getId())?->getDelivery()->getCiphertext());
        self::assertSame([], $events->events());
    }

    public function test_failures_rethrow_and_publish_command_failure(): void
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
                'expiry',
                UserId::generate(),
                PasswordResetDeliveryId::generate(),
                new DateTimeImmutable()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_confirmation_failures_rethrow_and_publish_command_failure(): void
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
                'transport',
                UserId::generate(),
                PasswordResetDeliveryId::generate(),
                new DateTimeImmutable()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_messages_round_trip_and_reject_missing_data(): void
    {
        $grant = $this->grant();
        $at = new DateTimeImmutable('2026-08-20T13:00:00+00:00');
        $messages = [
            new ConfirmPasswordResetDelivery('transport', $grant->getUserId(), $grant->getDelivery()->getId(), $at),
            new ExpirePasswordResetDelivery('expiry', $grant->getUserId(), $grant->getDelivery()->getId(), $at),
            new PasswordResetDeliveryConfirmed('transport', $grant->getUserId(), $grant->getDelivery()->getId(), $at),
            new PasswordResetDeliveryExpired('expiry', $grant->getUserId(), $grant->getDelivery()->getId(), $at),
        ];

        foreach ($messages as $message) {
            self::assertEquals($message, $message::fromArray($message->toArray()));
            try {
                $message::fromArray([]);
                self::fail($message::class.' accepted missing data.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('transport', $messages[2]->getActorId());
        self::assertSame($grant->getUserId(), $messages[2]->getUserId());
        self::assertSame($grant->getDelivery()->getId(), $messages[2]->getPasswordResetDeliveryId());
        self::assertSame($at, $messages[2]->getOccurredAt());
        self::assertSame('expiry', $messages[3]->getActorId());
        self::assertSame($grant->getUserId(), $messages[3]->getUserId());
        self::assertSame($grant->getDelivery()->getId(), $messages[3]->getPasswordResetDeliveryId());
        self::assertSame($at, $messages[3]->getOccurredAt());
    }

    private function grant(string $credential = 'once'): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            UserId::fromString('018f6300-4c42-7c43-9f19-9dfac6f7a001'),
            PasswordResetCredential::fromString($credential),
            new DateTimeImmutable('2026-08-20T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-20T13:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:'.$credential
        );
    }

    private function failingRepository(): PasswordResetGrantRepository
    {
        return new class implements PasswordResetGrantRepository {
            public function getById(PasswordResetGrantId $passwordResetGrantId): ?PasswordResetGrant
            {
                throw new RuntimeException('Password-reset persistence failed.');
            }

            public function getByDeliveryId(PasswordResetDeliveryId $passwordResetDeliveryId): ?PasswordResetGrant
            {
                throw new RuntimeException('Password-reset persistence failed.');
            }

            public function getLatestByUserId(UserId $userId): ?PasswordResetGrant
            {
                throw new RuntimeException('Password-reset persistence failed.');
            }

            public function add(PasswordResetGrant $passwordResetGrant): bool
            {
                return false;
            }

            public function appendAfterTerminal(
                PasswordResetGrant $terminalPredecessor,
                PasswordResetGrant $successor
            ): bool {
                return false;
            }

            public function replace(PasswordResetGrant $predecessor, PasswordResetGrant $replacement): bool
            {
                return false;
            }

            public function replaceWithSuccessor(
                PasswordResetGrant $predecessor,
                PasswordResetGrant $terminalPredecessor,
                PasswordResetGrant $successor
            ): bool {
                return false;
            }
        };
    }
}
