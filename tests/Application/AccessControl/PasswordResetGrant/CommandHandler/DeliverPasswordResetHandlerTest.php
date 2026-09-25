<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\EventSubscriber\PasswordResetDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception\PasswordResetDeliveryException;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\EventMessage;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Repository\InMemoryPasswordResetGrants;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryCommandBus;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverPasswordResetHandler::class)]
#[CoversClass(DeliverPasswordReset::class)]
#[CoversClass(PasswordResetDeliverySubscriber::class)]
final class DeliverPasswordResetHandlerTest extends TestCase
{
    public function test_requested_event_routes_provider_delivery_outside_both_transactions(): void
    {
        $grant = $this->grant();
        $commandBus = new InMemoryCommandBus();
        $subscriber = new PasswordResetDeliverySubscriber($commandBus);
        $subscriber->onPasswordResetRequested(EventMessage::create(new PasswordResetRequested(
            $grant->getUserId(),
            $grant->getDelivery()->getId(),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00')
        )));
        $command = $commandBus->executedCommands()[0];
        self::assertInstanceOf(DeliverPasswordReset::class, $command);
        self::assertSame([
            PasswordResetRequested::class => 'onPasswordResetRequested'
        ], PasswordResetDeliverySubscriber::eventRegistration());

        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
        self::assertTrue($repository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
            CredentialDeliveryInvocation $invocation
        ) use ($unitOfWork): void {
            self::assertFalse($unitOfWork->transactionActive);
            self::assertSame('password_reset', $invocation->getPurpose());
            self::assertSame('reset-once', $invocation->getCredential());
            self::assertSame('alice@example.test', $invocation->getEmail()->canonical());
        });
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();

        $this->handler($repository, $audit, $unitOfWork, $provider, $events)->handle(
            CommandMessage::create($command)
        );

        $stored = $repository->getLatestByUserId($grant->getUserId());
        self::assertSame(2, $unitOfWork->transactions);
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $stored->getDelivery()->getStatus());
        self::assertNull($stored->getDelivery()->getEncryptedMaterial());
        self::assertSame('user.password_reset_delivery.confirmed', $audit->all()[0]->action());
        self::assertInstanceOf(PasswordResetDeliveryConfirmed::class, $events->events()[0]);
        self::assertSame(DeliverPasswordReset::class, DeliverPasswordResetHandler::commandRegistration());
    }

    public function test_typed_and_unexpected_provider_failures_record_safe_outcomes(): void
    {
        foreach (
            [
            [CredentialDeliveryOutcome::RETRYABLE_FAILURE, CredentialDeliveryStatus::RETRY_PENDING],
            [CredentialDeliveryOutcome::PERMANENT_FAILURE, CredentialDeliveryStatus::PERMANENT_FAILURE],
            [new RuntimeException('private provider detail'), CredentialDeliveryStatus::RETRY_PENDING]
            ] as [$providerResult, $expectedStatus]
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $grant = $this->grant();
            $repository = new InMemoryPasswordResetGrants($unitOfWork);
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
            self::assertSame('user.password_reset_delivery.failed', $audit->all()[0]->action());
            self::assertSame([], $events->events());
            self::assertStringNotContainsString(
                'private provider detail',
                print_r($delivery, true).var_export($delivery, true)
            );
            if ($providerResult instanceof RuntimeException) {
                self::assertSame(CredentialDeliveryFailure::UNEXPECTED_PROVIDER, $delivery->getLastFailure());
            }
        }
    }

    public function test_missing_work_and_competing_claim_fail_before_invocation(): void
    {
        $provider = new RecordingCredentialDeliveryProvider();
        $events = new InMemoryEventDispatcher();
        try {
            $this->handler(
                new InMemoryPasswordResetGrants(),
                new InMemoryAuditEvidenceRepository(),
                new InMemoryUnitOfWork(),
                $provider,
                $events
            )->handle(CommandMessage::create(new DeliverPasswordReset(
                'anonymous',
                UserId::generate(),
                PasswordResetDeliveryId::generate()
            )));
            self::fail('Missing reset delivery was accepted.');
        } catch (PasswordResetDeliveryException) {
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $repository = new InMemoryPasswordResetGrants($unitOfWork, replaceFailureOnCall: 1);
        self::assertTrue($repository->add($grant));
        try {
            $this->handler($repository, new InMemoryAuditEvidenceRepository(), $unitOfWork, $provider)->handle(
                CommandMessage::create($this->command($grant))
            );
            self::fail('A competing reset-delivery claim was accepted.');
        } catch (PasswordResetDeliveryException) {
        }

        self::assertSame([], $provider->invocations());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    public function test_concurrent_outcome_makes_provider_result_stale(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
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

        $this->expectException(PasswordResetDeliveryException::class);
        $this->handler($repository, new InMemoryAuditEvidenceRepository(), $unitOfWork, $provider)->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_outcome_cas_loss_preserves_claim_without_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $repository = new InMemoryPasswordResetGrants($unitOfWork, replaceFailureOnCall: 2);
        self::assertTrue($repository->add($grant));
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);

        $this->expectException(PasswordResetDeliveryException::class);
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
        $repository = new InMemoryPasswordResetGrants($unitOfWork);
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

    public function test_command_round_trips_and_rejects_missing_data(): void
    {
        $command = $this->command($this->grant());
        self::assertEquals($command, DeliverPasswordReset::fromArray($command->toArray()));
        self::assertSame('anonymous', $command->getActorId());
        self::assertSame($command->getUserId(), $command->getUserId());
        self::assertSame($command->getPasswordResetDeliveryId(), $command->getPasswordResetDeliveryId());
        self::assertArrayNotHasKey('credential', $command->toArray());
        self::assertArrayNotHasKey('ciphertext', $command->toArray());

        foreach (['actor_id', 'user_id', 'password_reset_delivery_id'] as $missing) {
            $data = $command->toArray();
            unset($data[$missing]);
            try {
                DeliverPasswordReset::fromArray($data);
                self::fail('Missing delivery command data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(3);
    }

    private function grant(string $expiresAt = '2026-08-23T12:00:00+00:00'): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('reset-once'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00'),
            new DateTimeImmutable($expiresAt),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:reset-once'
        );
    }

    private function command(PasswordResetGrant $grant): DeliverPasswordReset
    {
        return new DeliverPasswordReset(
            'anonymous',
            $grant->getUserId(),
            $grant->getDelivery()->getId()
        );
    }

    private function handler(
        InMemoryPasswordResetGrants $repository,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $unitOfWork,
        ?RecordingCredentialDeliveryProvider $provider = null,
        ?InMemoryEventDispatcher $events = null
    ): DeliverPasswordResetHandler {
        return new DeliverPasswordResetHandler(
            $repository,
            $audit,
            $unitOfWork,
            new PrefixPasswordResetDeliveryCipher(),
            $provider ?? new RecordingCredentialDeliveryProvider(),
            new FixedClock('2026-08-23T11:00:00+00:00'),
            $events ?? new InMemoryEventDispatcher()
        );
    }
}
