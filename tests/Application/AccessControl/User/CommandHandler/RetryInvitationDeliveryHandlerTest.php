<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\RetryInvitationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\User\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryInvitationDeliveryRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryInvitationDeliveryHandler::class)]
#[CoversClass(InvitationDeliveryRetryRequested::class)]
#[CoversClass(RetryInvitationDelivery::class)]
final class RetryInvitationDeliveryHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{InvitationDelivery}>
     */
    public static function retryableWork(): iterable
    {
        $userId = UserId::generate();
        $pending = InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $failed = InvitationDelivery::create(
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
    public function test_that_it_publishes_a_retry_request_after_the_durable_commit(InvitationDelivery $work): void
    {
        $repository = new InMemoryInvitationDeliveryRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RetryInvitationDeliveryHandler($repository, $auditEvidenceRepository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $work->userId())));

        self::assertSame(RetryInvitationDelivery::class, RetryInvitationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertTrue($work->isRetryable());
        self::assertSame('ciphertext', $work->ciphertext());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertSame('user.invitation_delivery.retry_requested', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(InvitationDeliveryRetryRequested::class, $events->events()[0]);
    }

    public function test_that_terminal_delivery_work_does_not_publish_a_retry_request(): void
    {
        $userId = UserId::generate();
        $work = InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $work->expireAt(new DateTimeImmutable('2026-08-25T12:00:00+00:00'));

        $repository = new InMemoryInvitationDeliveryRepository();
        $repository->add($work);

        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(InvitationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_prevents_retry_request_publication(): void
    {
        $userId = UserId::generate();
        $work = InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $unitOfWork = new InMemoryUnitOfWork();

        $repository = new InMemoryInvitationDeliveryRepository($unitOfWork);
        $repository->add($work);

        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler($repository, $auditEvidenceRepository, $unitOfWork, $events);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit persistence write failed.');
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(0, $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new RetryInvitationDelivery('Admin-42', UserId::generate());
        $event = new InvitationDeliveryRetryRequested('Admin-42', $command->getUserId());

        self::assertEquals($command, RetryInvitationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, InvitationDeliveryRetryRequested::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        $this->expectException(DomainException::class);
        RetryInvitationDelivery::fromArray([]);
    }

    public function test_that_the_event_rejects_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        InvitationDeliveryRetryRequested::fromArray([]);
    }
}
