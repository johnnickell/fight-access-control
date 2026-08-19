<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\User\Exception\InvitationDeliveryNotResendableException;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\InvitationDeliveryRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\FailingInvitationDeliveryRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryInvitationDeliveryRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedInvitationClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\PrefixInvitationDeliveryCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ResendInvitationDeliveryHandler::class)]
#[CoversClass(InvitationDeliveryResent::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(ResendInvitationDelivery::class)]
final class ResendInvitationDeliveryHandlerTest extends TestCase
{
    public function test_that_it_revokes_the_predecessor_and_stages_a_replacement_before_dispatching_success(): void
    {
        $userId = UserId::generate();
        $predecessor = ActivationGrant::issue(
            $userId,
            'activate-old',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $activationGrantRepository->add($predecessor);

        $invitationDeliveryRepository = new InMemoryInvitationDeliveryRepository();
        $invitationDeliveryRepository->add(InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext:activate-old',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = $this->handler(
            $activationGrantRepository,
            $invitationDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));

        $grants = $activationGrantRepository->all();
        $deliveryWork = $invitationDeliveryRepository->all();
        self::assertSame(ResendInvitationDelivery::class, ResendInvitationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(2, $grants);
        self::assertTrue($grants[0]->isRevoked());
        self::assertFalse($grants[0]->isUsableAt(new DateTimeImmutable('2026-08-19T12:00:00+00:00')));
        self::assertSame('2026-08-19T12:00:00+00:00', $grants[0]->getRevokedAt()?->format(DATE_ATOM));
        self::assertSame(hash('sha256', 'activate-new'), $grants[1]->getCredentialHash());
        self::assertTrue($grants[1]->isUsableAt(new DateTimeImmutable('2026-08-19T12:00:00+00:00')));
        self::assertCount(2, $deliveryWork);
        self::assertSame('alice@example.test', $deliveryWork[1]->email());
        self::assertSame('ciphertext:activate-new', $deliveryWork[1]->ciphertext());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertSame('user.invitation_delivery.resent', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(InvitationDeliveryResent::class, $events->events()[0]);
    }

    public function test_that_a_delivery_storage_failure_restores_the_predecessor_before_rethrowing(): void
    {
        $userId = UserId::generate();
        $predecessor = ActivationGrant::issue(
            $userId,
            'activate-old',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $activationGrantRepository->add($predecessor);

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            new FailingInvitationDeliveryRepository(InvitationDelivery::create(
                $userId,
                'alice@example.test',
                'ciphertext:activate-old',
                new DateTimeImmutable('2026-08-25T12:00:00+00:00')
            )),
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The replacement delivery could not be stored.');
        try {
            $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
            self::assertCount(1, $activationGrantRepository->all());
            self::assertTrue($activationGrantRepository->all()[0]->isIssued());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_rolls_back_the_replacement_delivery(): void
    {
        $userId = UserId::generate();
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $activationGrantRepository->add(ActivationGrant::issue(
            $userId,
            'activate-old',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $invitationDeliveryRepository = new InMemoryInvitationDeliveryRepository($unitOfWork);
        $invitationDeliveryRepository->add(InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext:activate-old',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            $invitationDeliveryRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true),
            $unitOfWork,
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The audit persistence write failed.');
        try {
            $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(1, $activationGrantRepository->all());
            self::assertTrue($activationGrantRepository->all()[0]->isIssued());
            self::assertCount(1, $invitationDeliveryRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_it_rejects_a_missing_or_ineligible_predecessor_delivery(): void
    {
        $userId = UserId::generate();
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $activationGrantRepository->add(ActivationGrant::issue(
            $userId,
            'activate-old',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        )->revoke(new DateTimeImmutable('2026-08-19T11:00:00+00:00')));
        $invitationDeliveryRepository = new InMemoryInvitationDeliveryRepository();
        $invitationDeliveryRepository->add(InvitationDelivery::create(
            $userId,
            'alice@example.test',
            'ciphertext:activate-old',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            $invitationDeliveryRepository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(InvitationDeliveryNotResendableException::class);
        try {
            $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(1, $activationGrantRepository->all());
            self::assertCount(1, $invitationDeliveryRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new ResendInvitationDelivery('Admin-42', UserId::generate());
        $event = new InvitationDeliveryResent('Admin-42', $command->getUserId());

        self::assertEquals($command, ResendInvitationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, InvitationDeliveryResent::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        $this->expectException(DomainException::class);
        ResendInvitationDelivery::fromArray([]);
    }

    public function test_that_the_event_rejects_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        InvitationDeliveryResent::fromArray([]);
    }

    private function handler(
        InMemoryActivationGrantRepository $activationGrantRepository,
        InvitationDeliveryRepository $invitationDeliveryRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): ResendInvitationDeliveryHandler {
        return new ResendInvitationDeliveryHandler(
            $activationGrantRepository,
            $invitationDeliveryRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixInvitationDeliveryCipher(),
            new FixedInvitationClock('2026-08-19T12:00:00+00:00'),
            $events
        );
    }
}
