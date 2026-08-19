<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RetryActivationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\Command\RetryActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryRetried;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryActivationDeliveryHandler::class)]
#[CoversClass(ActivationDeliveryRetried::class)]
#[CoversClass(RetryActivationDelivery::class)]
final class RetryActivationDeliveryHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{ActivationDeliveryWork}>
     */
    public static function retryableWork(): iterable
    {
        $userId = UserId::generate();
        $pending = ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $failed = ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $failed->fail();

        yield 'pending work' => [$pending];
        yield 'failed work' => [$failed];
    }

    #[DataProvider('retryableWork')]
    public function test_that_it_confirms_retryable_delivery_after_the_durable_commit(
        ActivationDeliveryWork $work
    ): void {
        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $invoker = new RecordingActivationDeliveryInvoker();
        $handler = new RetryActivationDeliveryHandler($repository, $unitOfWork, $invoker, $events);

        $handler->handle(CommandMessage::create(new RetryActivationDelivery('Admin-42', $work->userId())));

        self::assertSame(RetryActivationDelivery::class, RetryActivationDeliveryHandler::commandRegistration());
        self::assertCount(1, $invoker->invokedWork());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(ActivationDeliveryStatus::CONFIRMED, $work->getStatus());
        self::assertNull($work->ciphertext());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(ActivationDeliveryRetried::class, $events->events()[0]);
    }

    public function test_that_an_invocation_failure_is_durably_observable_and_retryable_before_it_rethrows(): void
    {
        $userId = UserId::generate();
        $work = ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher();
        $invoker = new RecordingActivationDeliveryInvoker(new RuntimeException('Transport unavailable.'));
        $handler = new RetryActivationDeliveryHandler($repository, $unitOfWork, $invoker, $events);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transport unavailable.');
        try {
            $handler->handle(CommandMessage::create(new RetryActivationDelivery('Admin-42', $userId)));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame(ActivationDeliveryStatus::FAILED, $work->getStatus());
            self::assertSame('ciphertext', $work->ciphertext());
            self::assertTrue($work->isRetryable());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_terminal_delivery_work_is_not_invoked(): void
    {
        $userId = UserId::generate();
        $work = ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add($work);

        $events = new InMemoryEventDispatcher();
        $invoker = new RecordingActivationDeliveryInvoker();
        $handler = new RetryActivationDeliveryHandler(
            $repository,
            new InMemoryUnitOfWork(),
            $invoker,
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryActivationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(0, $invoker->invokedWork());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new RetryActivationDelivery('Admin-42', UserId::generate());
        $event = new ActivationDeliveryRetried('Admin-42', $command->getUserId());

        self::assertEquals($command, RetryActivationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, ActivationDeliveryRetried::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        $this->expectException(DomainException::class);
        RetryActivationDelivery::fromArray([]);
    }

    public function test_that_the_event_rejects_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        ActivationDeliveryRetried::fromArray([]);
    }
}
