<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationDeliveryWorkRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\RecordingActivationDeliveryInvoker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverUserInvitationHandler::class)]
#[CoversClass(DeliverUserInvitation::class)]
final class DeliverUserInvitationHandlerTest extends TestCase
{
    public function test_that_it_confirms_delivery_after_invocation_and_one_durable_commit(): void
    {
        $userId = UserId::generate();
        $work = $this->work($userId);
        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $handler = new DeliverUserInvitationHandler(
            $repository,
            $unitOfWork,
            new RecordingActivationDeliveryInvoker(),
            new InMemoryEventDispatcher()
        );

        $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', $userId)));

        self::assertSame(DeliverUserInvitation::class, DeliverUserInvitationHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(ActivationDeliveryStatus::CONFIRMED, $work->getStatus());
        self::assertNull($work->ciphertext());
    }

    public function test_that_an_invocation_failure_remains_retryable_then_rethrows_and_publishes_failure(): void
    {
        $userId = UserId::generate();
        $work = $this->work($userId);
        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $handler = new DeliverUserInvitationHandler(
            $repository,
            $unitOfWork,
            new RecordingActivationDeliveryInvoker(new RuntimeException('Transport unavailable.')),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transport unavailable.');
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', $userId)));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame(ActivationDeliveryStatus::FAILED, $work->getStatus());
            self::assertSame('ciphertext', $work->ciphertext());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_repository_error_is_rethrown_and_publishes_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new DeliverUserInvitationHandler(
            new class implements ActivationDeliveryWorkRepository {
                public function getByUserId(UserId $userId): ?ActivationDeliveryWork
                {
                    throw new RuntimeException('Delivery work storage is unavailable.');
                }

                public function add(ActivationDeliveryWork $work): void
                {
                }
            },
            new InMemoryUnitOfWork(),
            new RecordingActivationDeliveryInvoker(),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Delivery work storage is unavailable.');
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', UserId::generate())));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_missing_delivery_work_is_rejected_and_publishes_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new DeliverUserInvitationHandler(
            new InMemoryActivationDeliveryWorkRepository(),
            new InMemoryUnitOfWork(),
            new RecordingActivationDeliveryInvoker(),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', UserId::generate())));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_the_command_round_trips_and_rejects_missing_data(): void
    {
        $command = new DeliverUserInvitation('Admin-42', UserId::generate());

        self::assertEquals($command, DeliverUserInvitation::fromArray($command->toArray()));
        self::assertSame('Admin-42', $command->getActorId());
        $this->expectException(DomainException::class);
        DeliverUserInvitation::fromArray([]);
    }

    private function work(UserId $userId): ActivationDeliveryWork
    {
        return ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }
}
