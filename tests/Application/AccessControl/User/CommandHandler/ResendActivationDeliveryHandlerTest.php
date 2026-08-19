<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\ResendActivationDeliveryHandler;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWork;
use Fight\AccessControl\Domain\AccessControl\User\ActivationDeliveryWorkRepository;
use Fight\AccessControl\Domain\AccessControl\User\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\User\Command\ResendActivationDelivery;
use Fight\AccessControl\Domain\AccessControl\User\Event\ActivationDeliveryResent;
use Fight\AccessControl\Domain\AccessControl\User\Exception\ActivationDeliveryNotResendableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\FailingActivationDeliveryWorkRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationDeliveryWorkRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\FixedInvitationClock;
use Fight\Test\AccessControl\Application\AccessControl\User\Service\PrefixCipher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ResendActivationDeliveryHandler::class)]
#[CoversClass(ActivationDeliveryResent::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(ResendActivationDelivery::class)]
final class ResendActivationDeliveryHandlerTest extends TestCase
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

        $activationDeliveryWorkRepository = new InMemoryActivationDeliveryWorkRepository();
        $activationDeliveryWorkRepository->add(ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext:activate-old',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $handler = $this->handler(
            $activationGrantRepository,
            $activationDeliveryWorkRepository,
            $unitOfWork,
            $events
        );

        $handler->handle(CommandMessage::create(new ResendActivationDelivery('Admin-42', $userId)));

        $grants = $activationGrantRepository->all();
        $deliveryWork = $activationDeliveryWorkRepository->all();
        self::assertSame(ResendActivationDelivery::class, ResendActivationDeliveryHandler::commandRegistration());
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
        self::assertCount(1, $events->events());
        self::assertInstanceOf(ActivationDeliveryResent::class, $events->events()[0]);
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
            new FailingActivationDeliveryWorkRepository(ActivationDeliveryWork::create(
                $userId,
                'alice@example.test',
                'ciphertext:activate-old',
                new DateTimeImmutable('2026-08-25T12:00:00+00:00')
            )),
            $unitOfWork,
            $events
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The replacement delivery could not be stored.');
        try {
            $handler->handle(CommandMessage::create(new ResendActivationDelivery('Admin-42', $userId)));
        } finally {
            self::assertSame(1, $unitOfWork->transactions);
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
        $activationGrantRepository->add(ActivationGrant::issue(
            $userId,
            'activate-old',
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        )->revoke(new DateTimeImmutable('2026-08-19T11:00:00+00:00')));
        $activationDeliveryWorkRepository = new InMemoryActivationDeliveryWorkRepository();
        $activationDeliveryWorkRepository->add(ActivationDeliveryWork::create(
            $userId,
            'alice@example.test',
            'ciphertext:activate-old',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $events = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            $activationDeliveryWorkRepository,
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(ActivationDeliveryNotResendableException::class);
        try {
            $handler->handle(CommandMessage::create(new ResendActivationDelivery('Admin-42', $userId)));
        } finally {
            self::assertCount(1, $activationGrantRepository->all());
            self::assertCount(1, $activationDeliveryWorkRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new ResendActivationDelivery('Admin-42', UserId::generate());
        $event = new ActivationDeliveryResent('Admin-42', $command->getUserId());

        self::assertEquals($command, ResendActivationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, ActivationDeliveryResent::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        $this->expectException(DomainException::class);
        ResendActivationDelivery::fromArray([]);
    }

    public function test_that_the_event_rejects_missing_required_data(): void
    {
        $this->expectException(DomainException::class);
        ActivationDeliveryResent::fromArray([]);
    }

    private function handler(
        InMemoryActivationGrantRepository $activationGrantRepository,
        ActivationDeliveryWorkRepository $activationDeliveryWorkRepository,
        InMemoryUnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): ResendActivationDeliveryHandler {
        return new ResendActivationDeliveryHandler(
            $activationGrantRepository,
            $activationDeliveryWorkRepository,
            $unitOfWork,
            new FixedCredentialGenerator('activate-new'),
            new PrefixCipher(),
            new FixedInvitationClock('2026-08-19T12:00:00+00:00'),
            $events
        );
    }
}
