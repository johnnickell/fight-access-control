<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotResendableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ResendInvitationDeliveryHandler::class)]
#[CoversClass(InvitationDeliveryResent::class)]
#[CoversClass(ActivationCredential::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(ResendInvitationDelivery::class)]
final class ResendInvitationDeliveryHandlerTest extends TestCase
{
    public function test_that_it_revokes_the_predecessor_and_stages_a_replacement_before_dispatching_success(): void
    {
        $userId = UserId::generate();
        $predecessor = ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activate-old'
        );
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $activationGrantRepository->add($predecessor);

        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = $this->handler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));

        $grants = $activationGrantRepository->all();
        self::assertSame(ResendInvitationDelivery::class, ResendInvitationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(2, $grants);
        self::assertTrue($grants[0]->isRevoked());
        self::assertFalse($grants[0]->isUsableAt(new DateTimeImmutable('2026-08-19T12:00:00+00:00')));
        self::assertSame('2026-08-19T12:00:00+00:00', $grants[0]->getRevokedAt()?->format(DATE_ATOM));
        self::assertSame(hash('sha256', 'activate-new'), $grants[1]->getCredentialHash());
        self::assertTrue($grants[1]->isUsableAt(new DateTimeImmutable('2026-08-19T12:00:00+00:00')));
        self::assertSame('alice@example.test', $grants[1]->getDelivery()->getEmail()->canonical());
        self::assertSame('ciphertext:activate-new', $grants[1]->getDelivery()->getCiphertext());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertSame('user.invitation_delivery.resent', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(InvitationDeliveryResent::class, $events->events()[0]);
    }

    public function test_that_it_resends_a_confirmed_but_unredeemed_invitation(): void
    {
        $userId = UserId::generate();
        $issued = ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activate-old'
        );
        $claimed = $issued->claimDelivery();
        $confirmed = $claimed->confirmDelivery();
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        self::assertTrue($activationGrantRepository->add($issued));
        self::assertTrue($activationGrantRepository->replace($issued, $claimed));
        self::assertTrue($activationGrantRepository->replace($claimed, $confirmed));
        self::assertFalse($confirmed->getDelivery()->isRetryable());

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));

        $grants = $activationGrantRepository->all();
        self::assertCount(2, $grants);
        self::assertTrue($grants[0]->isRevoked());
        self::assertTrue($grants[1]->isIssued());
        self::assertSame('ciphertext:activate-new', $grants[1]->getDelivery()->getCiphertext());
        self::assertInstanceOf(InvitationDeliveryResent::class, $events->events()[0]);
    }

    public function test_that_a_delivery_storage_failure_restores_the_predecessor_before_rethrowing(): void
    {
        $userId = UserId::generate();
        $predecessor = ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activate-old'
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository(
            $unitOfWork,
            replaceWithSuccessorSucceeds: false
        );
        $activationGrantRepository->add($predecessor);

        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $events
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The activation grant changed concurrently.');
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
        self::assertTrue($activationGrantRepository->add(ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activate-old'
        )));
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
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
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_it_rejects_a_missing_or_ineligible_predecessor_delivery(): void
    {
        $userId = UserId::generate();
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $issued = ActivationGrant::issue(
            $userId,
            ActivationCredential::fromString('activate-old'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activate-old'
        );
        self::assertTrue($activationGrantRepository->add($issued));
        self::assertTrue($activationGrantRepository->replace(
            $issued,
            $issued->revoke(new DateTimeImmutable('2026-08-19T11:00:00+00:00'))
        ));
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(ActivationDeliveryNotResendableException::class);
        try {
            $handler->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(1, $activationGrantRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new ResendInvitationDelivery('Admin-42', UserId::generate());
        $event = new InvitationDeliveryResent(
            'Admin-42',
            $command->getUserId(),
            ActivationDeliveryId::generate()
        );

        self::assertEquals($command, ResendInvitationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, InvitationDeliveryResent::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        self::assertInstanceOf(ActivationDeliveryId::class, $event->getActivationDeliveryId());
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
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): ResendInvitationDeliveryHandler {
        return new ResendInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixInvitationDeliveryCipher(),
            new FixedClock('2026-08-19T12:00:00+00:00'),
            $events
        );
    }
}
