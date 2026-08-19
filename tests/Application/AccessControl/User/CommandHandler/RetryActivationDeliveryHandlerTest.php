<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RetryActivationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\Command\RetryActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\User\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationDeliveryWorkRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RetryActivationDeliveryHandler::class)]
#[CoversClass(ActivationDeliveryRetryRequested::class)]
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
    public function test_that_it_publishes_a_retry_request_after_the_durable_commit(ActivationDeliveryWork $work): void
    {
        $repository = new InMemoryActivationDeliveryWorkRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = new RetryActivationDeliveryHandler($repository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new RetryActivationDelivery('Admin-42', $work->userId())));

        self::assertSame(RetryActivationDelivery::class, RetryActivationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue($work->isRetryable());
        self::assertSame('ciphertext', $work->ciphertext());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(ActivationDeliveryRetryRequested::class, $events->events()[0]);
    }

    public function test_that_terminal_delivery_work_does_not_publish_a_retry_request(): void
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
        $handler = new RetryActivationDeliveryHandler($repository, new InMemoryUnitOfWork(), $events);

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryActivationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new RetryActivationDelivery('Admin-42', UserId::generate());
        $event = new ActivationDeliveryRetryRequested('Admin-42', $command->getUserId());

        self::assertEquals($command, RetryActivationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, ActivationDeliveryRetryRequested::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        $this->expectException(DomainException::class);
        RetryActivationDelivery::fromArray([]);
    }

    public function test_that_the_event_rejects_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        ActivationDeliveryRetryRequested::fromArray([]);
    }
}
