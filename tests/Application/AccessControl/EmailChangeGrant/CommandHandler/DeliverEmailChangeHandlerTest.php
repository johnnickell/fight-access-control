<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\DeliverEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EventSubscriber\EmailChangeDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\User;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\RecordingEmailChangeDeliveryInvoker;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryCommandBus;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverEmailChangeHandler::class)]
#[CoversClass(DeliverEmailChange::class)]
#[CoversClass(EmailChangeDeliverySubscriber::class)]
#[CoversClass(EmailChangeGrant::class)]
#[CoversClass(User::class)]
final class DeliverEmailChangeHandlerTest extends TestCase
{
    public function test_requested_event_routes_bounded_work_then_success_confirms_only_delivery_atomically(): void
    {
        $user = UserFixture::withState('old@example.test', UserState::ACTIVE);
        $user->requestEmailChange(EmailAddress::fromString('new@example.test'), new DateTimeImmutable(
            '2026-01-01T00:00:00+00:00'
        ));

        $grant = EmailChangeGrant::issue(
            $user->getId(),
            EmailChangeCredential::fromString('confirm-once'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:confirm-once'
        );
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($repository->add($grant));
        $commandBus = new InMemoryCommandBus();
        $subscriber = new EmailChangeDeliverySubscriber($commandBus);
        $subscriber->onEmailChangeRequested(EventMessage::create(new EmailChangeRequested(
            $user->getId(),
            $user->getId(),
            $grant->getDelivery()->getId(),
            EmailAddress::fromString('new@example.test'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00')
        )));
        self::assertSame([
            EmailChangeRequested::class => 'onEmailChangeRequested',
        ], EmailChangeDeliverySubscriber::eventRegistration());
        $command = $commandBus->executedCommands()[0];
        self::assertInstanceOf(DeliverEmailChange::class, $command);
        self::assertSame([
            'actor_id' => $user->getId()->toString(),
            'user_id' => $user->getId()->toString(),
            'email_change_delivery_id' => $grant->getDelivery()->getId()->toString(),
        ], $command->toArray());
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $invoker = new RecordingEmailChangeDeliveryInvoker();
        $handler = new DeliverEmailChangeHandler(
            $repository,
            $audit,
            $unitOfWork,
            $invoker,
            new InMemoryEventDispatcher()
        );

        $handler->handle(CommandMessage::create($command));

        $stored = $repository->getLatestByUserId($user->getId());
        self::assertInstanceOf(EmailChangeGrant::class, $stored);
        self::assertTrue($stored->isIssued());
        self::assertSame(EmailChangeDeliveryStatus::CONFIRMED, $stored->getDelivery()->getStatus());
        self::assertNull($stored->getDelivery()->getCiphertext());
        self::assertCount(1, $invoker->invokedWork());
        self::assertSame(EmailChangeDeliveryStatus::CLAIMED, $invoker->invokedWork()[0]->getStatus());
        self::assertSame('new@example.test', $invoker->invokedWork()[0]->getEmail()->canonical());
        self::assertSame('ciphertext:confirm-once', $invoker->invokedWork()[0]->getCiphertext());
        self::assertSame('old@example.test', $user->getEmail()->canonical());
        self::assertSame('new@example.test', $user->getPendingEmailChange()?->canonical());
        self::assertSame('user.email_change_delivery.confirmed', $audit->all()[0]->action());
        self::assertSame(1, $unitOfWork->transactions);
    }

    public function test_transport_failure_persists_retryable_work_then_rethrows_and_publishes_failure(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $grant = $this->grant();
        self::assertTrue($repository->add($grant));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $invoker = new RecordingEmailChangeDeliveryInvoker(new RuntimeException('Transport unavailable.'));
        $handler = $this->handler($repository, $audit, $unitOfWork, $invoker, $events);
        $command = new DeliverEmailChange(
            $grant->getUserId(),
            $grant->getUserId(),
            $grant->getDelivery()->getId()
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create($command));
        } finally {
            $stored = $repository->getLatestByUserId($grant->getUserId());
            self::assertInstanceOf(EmailChangeGrant::class, $stored);
            self::assertTrue($stored->isIssued());
            self::assertSame(EmailChangeDeliveryStatus::FAILED, $stored->getDelivery()->getStatus());
            self::assertTrue($stored->getDelivery()->isRetryable());
            self::assertSame('ciphertext:confirm-once', $stored->getDelivery()->getCiphertext());
            self::assertSame('user.email_change_delivery.failed', $audit->all()[0]->action());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($command, $events->events()[0]->getCommand());
            self::assertStringNotContainsString(
                'ciphertext:confirm-once',
                serialize($events->events()[0]->toArray())
            );
        }
    }

    public function test_failed_transport_work_can_be_claimed_again_and_confirmed(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $grant = $this->grant();
        self::assertTrue($repository->add($grant));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $command = new DeliverEmailChange(
            $grant->getUserId(),
            $grant->getUserId(),
            $grant->getDelivery()->getId()
        );

        try {
            $this->handler(
                $repository,
                $audit,
                $unitOfWork,
                new RecordingEmailChangeDeliveryInvoker(new RuntimeException('Transport unavailable.'))
            )->handle(CommandMessage::create($command));
            self::fail('The transport failure was not rethrown.');
        } catch (RuntimeException) {
        }

        $invoker = new RecordingEmailChangeDeliveryInvoker();
        $this->handler($repository, $audit, $unitOfWork, $invoker)->handle(CommandMessage::create($command));

        $stored = $repository->getLatestByUserId($grant->getUserId());
        self::assertInstanceOf(EmailChangeGrant::class, $stored);
        self::assertSame(EmailChangeDeliveryStatus::CONFIRMED, $stored->getDelivery()->getStatus());
        self::assertNull($stored->getDelivery()->getCiphertext());
        self::assertCount(1, $invoker->invokedWork());
        self::assertSame(EmailChangeDeliveryStatus::CLAIMED, $invoker->invokedWork()[0]->getStatus());
        self::assertSame('user.email_change_delivery.failed', $audit->all()[0]->action());
        self::assertSame('user.email_change_delivery.confirmed', $audit->all()[1]->action());
        self::assertSame(2, $unitOfWork->transactions);
    }

    public function test_stale_predecessor_callback_cannot_mutate_or_invoke_the_successor(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        $predecessor = $this->grant();
        self::assertTrue($repository->add($predecessor));
        $terminal = $predecessor->revoke(new DateTimeImmutable('2026-08-23T11:30:00+00:00'));
        self::assertTrue($repository->replace($predecessor, $terminal));
        $successor = EmailChangeGrant::issue(
            $predecessor->getUserId(),
            EmailChangeCredential::fromString('successor-confirmation'),
            new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-23T13:00:00+00:00'),
            EmailAddress::fromString('successor@example.test'),
            'ciphertext:successor-confirmation'
        );
        self::assertTrue($repository->appendAfterTerminal($terminal, $successor));
        $invoker = new RecordingEmailChangeDeliveryInvoker();
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->expectException(EmailChangeDeliveryNotRetryableException::class);
        try {
            $this->handler($repository, $audit, $unitOfWork, $invoker, $events)->handle(
                CommandMessage::create(new DeliverEmailChange(
                    $predecessor->getUserId(),
                    $predecessor->getUserId(),
                    $predecessor->getDelivery()->getId()
                ))
            );
        } finally {
            self::assertSame([], $invoker->invokedWork());
            self::assertSame([], $audit->all());
            self::assertSame($successor, $repository->getLatestByUserId($successor->getUserId()));
            self::assertSame('ciphertext:successor-confirmation', $successor->getDelivery()->getCiphertext());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_claim_cas_loss_prevents_transport_invocation(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork, replaceFailureOnCall: 1);
        $grant = $this->grant();
        self::assertTrue($repository->add($grant));
        $invoker = new RecordingEmailChangeDeliveryInvoker();
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->expectException(EmailChangeDeliveryNotRetryableException::class);
        try {
            $this->handler($repository, $audit, $unitOfWork, $invoker, $events)->handle(
                CommandMessage::create(new DeliverEmailChange(
                    $grant->getUserId(),
                    $grant->getUserId(),
                    $grant->getDelivery()->getId()
                ))
            );
        } finally {
            self::assertSame([], $invoker->invokedWork());
            self::assertSame([], $audit->all());
            self::assertSame($grant, $repository->getLatestByUserId($grant->getUserId()));
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_terminal_delivery_cas_loss_rolls_back_claim_and_audit(): void
    {
        foreach ([false, true] as $transportFails) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryEmailChangeGrantRepository($unitOfWork, replaceFailureOnCall: 2);
            $grant = $this->grant();
            self::assertTrue($repository->add($grant));
            $failure = $transportFails ? new RuntimeException('Transport unavailable.') : null;
            $invoker = new RecordingEmailChangeDeliveryInvoker($failure);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);

            try {
                $this->handler($repository, $audit, $unitOfWork, $invoker)->handle(
                    CommandMessage::create(new DeliverEmailChange(
                        $grant->getUserId(),
                        $grant->getUserId(),
                        $grant->getDelivery()->getId()
                    ))
                );
                self::fail('A terminal delivery compare-and-set loss was accepted.');
            } catch (EmailChangeDeliveryNotRetryableException) {
                self::assertSame($grant, $repository->getLatestByUserId($grant->getUserId()));
                self::assertSame([], $audit->all());
                self::assertCount(1, $invoker->invokedWork());
            }
        }
    }

    public function test_missing_work_and_command_data_are_rejected_secret_free(): void
    {
        $command = new DeliverEmailChange(
            UserId::generate(),
            UserId::generate(),
            EmailChangeDeliveryId::generate()
        );
        self::assertSame(DeliverEmailChange::class, DeliverEmailChangeHandler::commandRegistration());
        self::assertEquals($command, DeliverEmailChange::fromArray($command->toArray()));
        self::assertEquals($command->getActorId(), DeliverEmailChange::fromArray($command->toArray())->getActorId());
        self::assertEquals($command->getUserId(), DeliverEmailChange::fromArray($command->toArray())->getUserId());
        self::assertEquals(
            $command->getEmailChangeDeliveryId(),
            DeliverEmailChange::fromArray($command->toArray())->getEmailChangeDeliveryId()
        );
        self::assertArrayNotHasKey('credential', $command->toArray());
        self::assertArrayNotHasKey('ciphertext', $command->toArray());
        $events = new InMemoryEventDispatcher();

        try {
            $this->handler(
                new InMemoryEmailChangeGrantRepository(),
                new InMemoryAuditEvidenceRepository(),
                new InMemoryUnitOfWork(),
                events: $events
            )->handle(CommandMessage::create($command));
            self::fail('Missing delivery work was accepted.');
        } catch (EmailChangeDeliveryNotRetryableException) {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }

        foreach (['actor_id', 'user_id', 'email_change_delivery_id'] as $missing) {
            $data = $command->toArray();
            unset($data[$missing]);

            try {
                DeliverEmailChange::fromArray($data);
                self::fail('Missing delivery command data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(3);
    }

    private function grant(): EmailChangeGrant
    {
        return EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('confirm-once'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00'),
            new DateTimeImmutable('2026-08-23T12:00:00+00:00'),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:confirm-once'
        );
    }

    private function handler(
        InMemoryEmailChangeGrantRepository $repository,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        ?RecordingEmailChangeDeliveryInvoker $invoker = null,
        ?InMemoryEventDispatcher $events = null
    ): DeliverEmailChangeHandler {
        return new DeliverEmailChangeHandler(
            $repository,
            $audit,
            $unitOfWork,
            $invoker ?? new RecordingEmailChangeDeliveryInvoker(),
            $events ?? new InMemoryEventDispatcher()
        );
    }
}
