<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Domain\AccessControl\User\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryInvitationDeliveryRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\RecordingInvitationDeliveryInvoker;
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
        $repository = new InMemoryInvitationDeliveryRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new DeliverUserInvitationHandler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            new RecordingInvitationDeliveryInvoker(),
            new InMemoryEventDispatcher()
        );

        $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', $userId)));

        self::assertSame(DeliverUserInvitation::class, DeliverUserInvitationHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(InvitationDeliveryStatus::CONFIRMED, $work->getStatus());
        self::assertNull($work->ciphertext());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertSame('user.invitation_delivery.confirmed', $auditEvidenceRepository->all()[0]->action());
    }

    public function test_that_an_invocation_failure_remains_retryable_then_rethrows_and_publishes_failure(): void
    {
        $userId = UserId::generate();
        $work = $this->work($userId);
        $repository = new InMemoryInvitationDeliveryRepository();
        $repository->add($work);

        $unitOfWork = new InMemoryUnitOfWork();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new DeliverUserInvitationHandler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            new RecordingInvitationDeliveryInvoker(new RuntimeException('Transport unavailable.')),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Transport unavailable.');
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', $userId)));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertSame(InvitationDeliveryStatus::FAILED, $work->getStatus());
            self::assertSame('ciphertext', $work->ciphertext());
            self::assertCount(1, $auditEvidenceRepository->all());
            self::assertSame('user.invitation_delivery.failed', $auditEvidenceRepository->all()[0]->action());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_repository_error_is_rethrown_and_publishes_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new DeliverUserInvitationHandler(
            new class implements InvitationDeliveryRepository {
                public function getByUserId(UserId $userId): ?InvitationDelivery
                {
                    throw new RuntimeException('Delivery work storage is unavailable.');
                }

                public function add(InvitationDelivery $work): void
                {
                }
            },
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new RecordingInvitationDeliveryInvoker(),
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
            new InMemoryInvitationDeliveryRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new RecordingInvitationDeliveryInvoker(),
            $events
        );

        $this->expectException(InvitationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', UserId::generate())));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_preserves_pending_delivery_work(): void
    {
        $userId = UserId::generate();
        $work = $this->work($userId);
        $unitOfWork = new InMemoryUnitOfWork();

        $repository = new InMemoryInvitationDeliveryRepository($unitOfWork);
        $repository->add($work);

        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new DeliverUserInvitationHandler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            new RecordingInvitationDeliveryInvoker(),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit persistence write failed.');
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation('Admin-42', $userId)));
        } finally {
            self::assertSame(InvitationDeliveryStatus::PENDING, $work->getStatus());
            self::assertSame('ciphertext', $work->ciphertext());
            self::assertCount(0, $auditEvidenceRepository->all());
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

    private function work(UserId $userId): InvitationDelivery
    {
        return InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }
}
