<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\UserInvitationDelivered;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\RecordingInvitationDeliveryInvoker;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverUserInvitationHandler::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(DeliverUserInvitation::class)]
#[CoversClass(UserInvitationDelivered::class)]
final class DeliverUserInvitationHandlerTest extends TestCase
{
    public function test_that_it_confirms_delivery_after_invocation_and_one_durable_commit(): void
    {
        $activationGrant = $this->grant();
        $repository = new InMemoryActivationGrantRepository();
        self::assertTrue($repository->add($activationGrant));
        $unitOfWork = new InMemoryUnitOfWork();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $invoker = new RecordingInvitationDeliveryInvoker();
        $handler = $this->handler($repository, $auditEvidenceRepository, $unitOfWork, $invoker, $events);

        $handler->handle(CommandMessage::create(new DeliverUserInvitation(
            'Admin-42',
            $activationGrant->getUserId(),
            $activationGrant->getDelivery()->getId()
        )));

        $replacement = $repository->getLatestByUserId($activationGrant->getUserId());
        self::assertSame(DeliverUserInvitation::class, DeliverUserInvitationHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(ActivationDeliveryStatus::CONFIRMED, $replacement->getDelivery()->getStatus());
        self::assertNull($replacement->getDelivery()->getCiphertext());
        self::assertSame('user.invitation_delivery.confirmed', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $invoker->invokedWork());
        self::assertSame($activationGrant->getDelivery()->getId(), $invoker->invokedWork()[0]->getId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(UserInvitationDelivered::class, $events->events()[0]);
    }

    public function test_that_an_invocation_failure_remains_retryable_then_rethrows_and_publishes_failure(): void
    {
        $activationGrant = $this->grant();
        $repository = new InMemoryActivationGrantRepository();
        self::assertTrue($repository->add($activationGrant));
        $unitOfWork = new InMemoryUnitOfWork();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            new RecordingInvitationDeliveryInvoker(new RuntimeException('Transport unavailable.')),
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                $activationGrant->getUserId(),
                $activationGrant->getDelivery()->getId()
            )));
        } finally {
            $replacement = $repository->getLatestByUserId($activationGrant->getUserId());
            self::assertSame(ActivationDeliveryStatus::FAILED, $replacement->getDelivery()->getStatus());
            self::assertSame('ciphertext', $replacement->getDelivery()->getCiphertext());
            self::assertSame('user.invitation_delivery.failed', $auditEvidenceRepository->all()[0]->action());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertCount(1, $events->events());
        }
    }

    public function test_that_missing_delivery_work_is_rejected_and_publishes_command_failure(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            new InMemoryActivationGrantRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new RecordingInvitationDeliveryInvoker(),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                UserId::generate(),
                ActivationDeliveryId::generate()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_rolls_back_confirmation(): void
    {
        $activationGrant = $this->grant();
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($repository->add($activationGrant));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $handler = $this->handler($repository, $auditEvidenceRepository, $unitOfWork);

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                $activationGrant->getUserId(),
                $activationGrant->getDelivery()->getId()
            )));
        } finally {
            self::assertSame($activationGrant, $repository->getLatestByUserId($activationGrant->getUserId()));
            self::assertCount(0, $auditEvidenceRepository->all());
        }
    }

    public function test_that_a_failed_commit_publishes_no_success_event(): void
    {
        $activationGrant = $this->grant();
        $repository = new InMemoryActivationGrantRepository();
        self::assertTrue($repository->add($activationGrant));
        $events = new InMemoryEventDispatcher();
        $unitOfWork = new class implements UnitOfWork {
            public function commit(): void
            {
            }

            public function commitTransactional(callable $operation): mixed
            {
                $operation();

                throw new RuntimeException('Commit failed.');
            }

            public function isClosed(): bool
            {
                return false;
            }
        };
        $handler = new DeliverUserInvitationHandler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            $unitOfWork,
            new RecordingInvitationDeliveryInvoker(),
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Commit failed.');
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                $activationGrant->getUserId(),
                $activationGrant->getDelivery()->getId()
            )));
        } finally {
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_a_same_generation_cas_loser_is_rejected(): void
    {
        $activationGrant = $this->grant();
        $repository = new InMemoryActivationGrantRepository(replaceFailureOnCall: 2);
        self::assertTrue($repository->add($activationGrant));
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new RecordingInvitationDeliveryInvoker(),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $handler->handle(CommandMessage::create(new DeliverUserInvitation(
            'Admin-42',
            $activationGrant->getUserId(),
            $activationGrant->getDelivery()->getId()
        )));
    }

    public function test_that_a_failed_invocation_cas_loser_is_rejected(): void
    {
        $activationGrant = $this->grant();
        $repository = new InMemoryActivationGrantRepository(replaceFailureOnCall: 2);
        self::assertTrue($repository->add($activationGrant));
        $handler = $this->handler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new RecordingInvitationDeliveryInvoker(new RuntimeException('Transport unavailable.'))
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        $handler->handle(CommandMessage::create(new DeliverUserInvitation(
            'Admin-42',
            $activationGrant->getUserId(),
            $activationGrant->getDelivery()->getId()
        )));
    }

    public function test_that_a_stale_delivery_callback_cannot_invoke_the_latest_generation(): void
    {
        $predecessor = $this->grant();
        $successor = ActivationGrant::issue(
            $predecessor->getUserId(),
            ActivationCredential::fromString('activate-new'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'new-ciphertext'
        );
        $repository = new InMemoryActivationGrantRepository();
        self::assertTrue($repository->add($predecessor));
        self::assertTrue($repository->replaceWithSuccessor(
            $predecessor,
            $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
            $successor
        ));
        $invoker = new RecordingInvitationDeliveryInvoker();
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $invoker,
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                $predecessor->getUserId(),
                $predecessor->getDelivery()->getId()
            )));
        } finally {
            self::assertSame([], $invoker->invokedWork());
            self::assertSame($successor, $repository->getLatestByUserId($successor->getUserId()));
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_replacement_between_lookup_and_invocation_prevents_stale_ciphertext_invocation(): void
    {
        $predecessor = $this->grant();
        $successor = ActivationGrant::issue(
            $predecessor->getUserId(),
            ActivationCredential::fromString('activate-new'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'new-ciphertext'
        );
        $repository = new InMemoryActivationGrantRepository(beforeReplace: static function (
            InMemoryActivationGrantRepository $repository,
            ActivationGrant $current
        ) use ($successor): void {
            self::assertTrue($repository->replaceWithSuccessor(
                $current,
                $current->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
                $successor
            ));
        });
        self::assertTrue($repository->add($predecessor));
        $invoker = new RecordingInvitationDeliveryInvoker();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $handler = $this->handler(
            $repository,
            $auditEvidenceRepository,
            new InMemoryUnitOfWork(),
            $invoker
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                $predecessor->getUserId(),
                $predecessor->getDelivery()->getId()
            )));
        } finally {
            self::assertSame([], $invoker->invokedWork());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertSame($successor, $repository->getLatestByUserId($predecessor->getUserId()));
        }
    }

    public function test_that_the_command_round_trips_and_rejects_missing_data(): void
    {
        $command = new DeliverUserInvitation(
            'Admin-42',
            UserId::generate(),
            ActivationDeliveryId::generate()
        );

        self::assertEquals($command, DeliverUserInvitation::fromArray($command->toArray()));
        self::assertSame('Admin-42', $command->getActorId());
        $this->expectException(DomainException::class);
        DeliverUserInvitation::fromArray([]);
    }

    public function test_that_the_success_event_round_trips_and_rejects_missing_data(): void
    {
        $activationGrant = $this->grant();
        $event = new UserInvitationDelivered(
            'Admin-42',
            $activationGrant->getUserId(),
            $activationGrant->getDelivery()->getId()
        );

        self::assertEquals($event, UserInvitationDelivered::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($activationGrant->getUserId(), $event->getUserId());
        self::assertSame($activationGrant->getDelivery()->getId(), $event->getActivationDeliveryId());
        $this->expectException(DomainException::class);
        UserInvitationDelivered::fromArray([]);
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

    private function handler(
        InMemoryActivationGrantRepository $repository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        ?RecordingInvitationDeliveryInvoker $invoker = null,
        ?InMemoryEventDispatcher $events = null
    ): DeliverUserInvitationHandler {
        return new DeliverUserInvitationHandler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            $invoker ?? new RecordingInvitationDeliveryInvoker(),
            $events ?? new InMemoryEventDispatcher()
        );
    }
}
