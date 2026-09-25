<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\CommandHandler\DeliverEmailChangeHandler;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\EventSubscriber\EmailChangeDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Command\DeliverEmailChange;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeGrant;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeDelivered;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event\EmailChangeRequested;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception\EmailChangeDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Repository\InMemoryEmailChangeGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service\PrefixEmailChangeDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryCommandBus;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverEmailChangeHandler::class)]
#[CoversClass(DeliverEmailChange::class)]
#[CoversClass(EmailChangeDelivered::class)]
#[CoversClass(EmailChangeDeliverySubscriber::class)]
final class DeliverEmailChangeHandlerTest extends TestCase
{
    public function test_requested_event_routes_claimed_work_and_provider_runs_outside_transactions(): void
    {
        $grant = $this->grant();
        $commandBus = new InMemoryCommandBus();
        $subscriber = new EmailChangeDeliverySubscriber($commandBus);
        $subscriber->onEmailChangeRequested(EventMessage::create(new EmailChangeRequested(
            $grant->getUserId(),
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            $grant->getDelivery()->getEmail(),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00')
        )));
        $command = $commandBus->executedCommands()[0];
        self::assertInstanceOf(DeliverEmailChange::class, $command);
        self::assertSame([
            EmailChangeRequested::class => 'onEmailChangeRequested'
        ], EmailChangeDeliverySubscriber::eventRegistration());

        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($repository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
            CredentialDeliveryInvocation $invocation
        ) use ($unitOfWork): void {
            self::assertFalse($unitOfWork->transactionActive);
            self::assertSame('email_change', $invocation->getPurpose());
            self::assertSame('confirm-once', $invocation->getCredential());
            self::assertSame('new@example.test', $invocation->getEmail()->canonical());
        });
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->handler($repository, $audit, $unitOfWork, $provider, $events)->handle(
            CommandMessage::create($command)
        );

        $stored = $repository->getLatestByUserId($grant->getUserId());
        self::assertSame(2, $unitOfWork->transactions);
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $stored?->getDelivery()->getStatus());
        self::assertSame('user.email_change_delivery.confirmed', $audit->all()[0]->action());
        self::assertInstanceOf(EmailChangeDelivered::class, $events->events()[0]);
        self::assertSame(DeliverEmailChange::class, DeliverEmailChangeHandler::commandRegistration());
    }

    public function test_each_typed_failure_and_unexpected_throwable_records_only_safe_state(): void
    {
        foreach (
            [
            [CredentialDeliveryOutcome::RETRYABLE_FAILURE, CredentialDeliveryStatus::RETRY_PENDING],
            [CredentialDeliveryOutcome::PERMANENT_FAILURE, CredentialDeliveryStatus::PERMANENT_FAILURE],
            [new RuntimeException('vendor secret'), CredentialDeliveryStatus::RETRY_PENDING]
            ] as [$providerResult, $expectedStatus]
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $grant = $this->grant();
            $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
            self::assertTrue($repository->add($grant));
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();

            $this->handler(
                $repository,
                $audit,
                $unitOfWork,
                new RecordingCredentialDeliveryProvider([$providerResult]),
                $events
            )->handle(CommandMessage::create($this->command($grant)));

            $delivery = $repository->getLatestByUserId($grant->getUserId())?->getDelivery();
            self::assertSame($expectedStatus, $delivery?->getStatus());
            self::assertSame('user.email_change_delivery.failed', $audit->all()[0]->action());
            self::assertSame([], $events->events());
            self::assertStringNotContainsString(
                'vendor secret',
                print_r($delivery, true).var_export($delivery, true)
            );
            if ($providerResult instanceof RuntimeException) {
                self::assertSame(CredentialDeliveryFailure::UNEXPECTED_PROVIDER, $delivery->getLastFailure());
            }
        }
    }

    public function test_missing_work_and_claim_cas_loss_fail_before_provider_invocation(): void
    {
        $provider = new RecordingCredentialDeliveryProvider();
        $events = new InMemoryEventDispatcher();
        try {
            $this->handler(
                new InMemoryEmailChangeGrantRepository(),
                new InMemoryAuditEvidenceRepository(),
                new InMemoryUnitOfWork(),
                $provider,
                $events
            )->handle(CommandMessage::create(new DeliverEmailChange(
                UserId::generate(),
                UserId::generate(),
                EmailChangeDeliveryId::generate()
            )));
            self::fail('Missing delivery work was accepted.');
        } catch (EmailChangeDeliveryNotRetryableException) {
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork, replaceFailureOnCall: 1);
        self::assertTrue($repository->add($grant));
        try {
            $this->handler($repository, new InMemoryAuditEvidenceRepository(), $unitOfWork, $provider)->handle(
                CommandMessage::create($this->command($grant))
            );
            self::fail('A competing claim was accepted.');
        } catch (EmailChangeDeliveryNotRetryableException) {
        }

        self::assertSame([], $provider->invocations());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    public function test_concurrent_outcome_makes_provider_result_stale(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($repository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use (
            $repository,
            $grant
        ): void {
            $claimed = $repository->getLatestByUserId($grant->getUserId());
            $claimToken = $claimed?->getDelivery()->getClaimToken();
            self::assertNotNull($claimed);
            self::assertNotNull($claimToken);
            self::assertTrue($repository->replace(
                $claimed,
                $claimed->confirmDelivery($claimToken, new DateTimeImmutable('2026-08-23T11:00:30+00:00'))
            ));
        });

        $this->expectException(EmailChangeDeliveryNotRetryableException::class);
        $this->handler($repository, new InMemoryAuditEvidenceRepository(), $unitOfWork, $provider)->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_outcome_cas_loss_preserves_claim_and_rolls_back_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork, replaceFailureOnCall: 2);
        self::assertTrue($repository->add($grant));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);

        $this->expectException(EmailChangeDeliveryNotRetryableException::class);
        try {
            $this->handler($repository, $audit, $unitOfWork)->handle(
                CommandMessage::create($this->command($grant))
            );
        } finally {
            self::assertSame([], $audit->all());
            self::assertSame(
                CredentialDeliveryStatus::CLAIMED,
                $repository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
            );
        }
    }

    public function test_claim_lease_is_capped_at_expiry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant('2026-08-23T11:01:00+00:00');
        $repository = new InMemoryEmailChangeGrantRepository($unitOfWork);
        self::assertTrue($repository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use (
            $repository,
            $grant
        ): void {
            self::assertEquals(
                $grant->getExpiresAt(),
                $repository->getLatestByUserId($grant->getUserId())?->getDelivery()->getLeaseUntil()
            );
        });

        $this->handler($repository, new InMemoryAuditEvidenceRepository(), $unitOfWork, $provider)->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_command_and_success_event_round_trip_without_sensitive_material(): void
    {
        $command = $this->command($this->grant());
        self::assertEquals($command, DeliverEmailChange::fromArray($command->toArray()));
        self::assertArrayNotHasKey('credential', $command->toArray());
        self::assertArrayNotHasKey('ciphertext', $command->toArray());
        $event = new EmailChangeDelivered(
            $command->getActorId(),
            $command->getUserId(),
            $command->getEmailChangeDeliveryId()
        );
        self::assertEquals($event, EmailChangeDelivered::fromArray($event->toArray()));
        self::assertSame($command->getActorId(), $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        self::assertSame($command->getEmailChangeDeliveryId(), $event->getEmailChangeDeliveryId());

        foreach (['actor_id', 'user_id', 'email_change_delivery_id'] as $missing) {
            $data = $command->toArray();
            unset($data[$missing]);
            try {
                DeliverEmailChange::fromArray($data);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }

            $data = $event->toArray();
            unset($data[$missing]);
            try {
                EmailChangeDelivered::fromArray($data);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(6);
    }

    private function grant(string $expiresAt = '2026-08-23T12:00:00+00:00'): EmailChangeGrant
    {
        return EmailChangeGrant::issue(
            UserId::generate(),
            EmailChangeCredential::fromString('confirm-once'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00'),
            new DateTimeImmutable($expiresAt),
            EmailAddress::fromString('new@example.test'),
            'ciphertext:confirm-once'
        );
    }

    private function command(EmailChangeGrant $grant): DeliverEmailChange
    {
        return new DeliverEmailChange(
            $grant->getUserId(),
            $grant->getUserId(),
            $grant->getDelivery()->getId()
        );
    }

    private function handler(
        InMemoryEmailChangeGrantRepository $repository,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        ?RecordingCredentialDeliveryProvider $provider = null,
        ?InMemoryEventDispatcher $events = null
    ): DeliverEmailChangeHandler {
        return new DeliverEmailChangeHandler(
            $repository,
            $audit,
            $unitOfWork,
            new PrefixEmailChangeDeliveryCipher(),
            $provider ?? new RecordingCredentialDeliveryProvider(),
            new FixedClock('2026-08-23T11:00:00+00:00'),
            $events ?? new InMemoryEventDispatcher()
        );
    }
}
