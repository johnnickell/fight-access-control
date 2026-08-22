<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\RetryInvitationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RetryInvitationDeliveryHandler::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(InvitationDeliveryRetryRequested::class)]
#[CoversClass(RetryInvitationDelivery::class)]
final class RetryInvitationDeliveryHandlerTest extends TestCase
{
    public function test_that_it_publishes_a_retry_request_after_the_durable_commit(): void
    {
        $activationGrant = $this->grant()->failDelivery();

        $repository = new InMemoryActivationGrantRepository();
        self::assertTrue($repository->add($activationGrant));
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RetryInvitationDeliveryHandler($repository, $auditEvidenceRepository, $unitOfWork, $events);

        $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
            'Admin-42',
            $activationGrant->getUserId()
        )));

        self::assertSame(RetryInvitationDelivery::class, RetryInvitationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        $retryRequested = $repository->getLatestByUserId($activationGrant->getUserId());
        self::assertTrue($retryRequested?->getDelivery()->isRetryable());
        self::assertSame('ciphertext', $retryRequested->getDelivery()->getCiphertext());
        self::assertSame('user.invitation_delivery.retry_requested', $auditEvidenceRepository->all()[0]->action());
        self::assertInstanceOf(InvitationDeliveryRetryRequested::class, $events->events()[0]);
    }

    public function test_that_terminal_delivery_work_does_not_publish_a_retry_request(): void
    {
        $activationGrant = $this->grant()->confirmDelivery();
        $repository = new InMemoryActivationGrantRepository();
        self::assertTrue($repository->add($activationGrant));
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_missing_delivery_work_does_not_publish_a_retry_request(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            new InMemoryActivationGrantRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                UserId::generate()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_prevents_retry_request_publication(): void
    {
        $activationGrant = $this->grant()->failDelivery();
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($repository->add($activationGrant));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler($repository, $auditEvidenceRepository, $unitOfWork, $events);

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertCount(0, $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_concurrent_replacement_before_retry_cas_has_no_audit_or_success_event(): void
    {
        $activationGrant = $this->grant()->failDelivery();
        $successor = ActivationGrant::issue(
            $activationGrant->getUserId(),
            ActivationCredential::fromString('activate-new'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'new-ciphertext'
        );
        $repository = new InMemoryActivationGrantRepository(beforeReplace: static function (
            InMemoryActivationGrantRepository $repository,
            ActivationGrant $predecessor
        ) use ($successor): void {
            self::assertTrue($repository->replaceWithSuccessor(
                $predecessor,
                $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
                $successor
            ));
        });
        self::assertTrue($repository->add($activationGrant));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $auditEvidenceRepository,
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($successor, $repository->getLatestByUserId($activationGrant->getUserId()));
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new RetryInvitationDelivery('Admin-42', UserId::generate());
        $event = new InvitationDeliveryRetryRequested(
            'Admin-42',
            $command->getUserId(),
            ActivationDeliveryId::generate()
        );

        self::assertEquals($command, RetryInvitationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, InvitationDeliveryRetryRequested::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        self::assertInstanceOf(ActivationDeliveryId::class, $event->getActivationDeliveryId());
        $this->expectException(DomainException::class);
        RetryInvitationDelivery::fromArray([]);
    }

    public function test_that_the_event_rejects_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        InvitationDeliveryRetryRequested::fromArray([]);
    }

    private function grant(): ActivationGrant
    {
        return ActivationGrant::issue(
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
    }
}
