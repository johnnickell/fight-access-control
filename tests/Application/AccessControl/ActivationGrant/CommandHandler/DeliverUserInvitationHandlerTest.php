<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryAttemptResult;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\UserInvitationDelivered;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DeliverUserInvitationHandler::class)]
#[CoversClass(CredentialDeliveryAttemptResult::class)]
#[CoversClass(CredentialDeliveryInvocation::class)]
#[CoversClass(DeliverUserInvitation::class)]
#[CoversClass(UserInvitationDelivered::class)]
final class DeliverUserInvitationHandlerTest extends TestCase
{
    public function test_it_claims_commits_invokes_outside_transactions_and_commits_success(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
            CredentialDeliveryInvocation $invocation
        ) use (
            $unitOfWork,
            $activationGrantRepository,
            $grant
): void {
            self::assertFalse($unitOfWork->transactionActive);
            self::assertSame('activation', $invocation->getPurpose());
            self::assertSame($grant->getDelivery()->getId()->toString(), $invocation->getIdempotencyId());
            self::assertSame('alice@example.test', $invocation->getEmail()->canonical());
            self::assertSame('activate-once', $invocation->getCredential());
            self::assertSame(
                CredentialDeliveryStatus::CLAIMED,
                $activationGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
            );
        });
        $eventDispatcher = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertFalse($unitOfWork->transactionActive);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);

        $this->handler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $provider,
            $eventDispatcher
        )->handle(
            CommandMessage::create($this->command($grant))
        );

        $stored = $activationGrantRepository->getLatestByUserId($grant->getUserId());
        self::assertSame(2, $unitOfWork->transactions);
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $stored->getDelivery()->getStatus());
        self::assertNull($stored->getDelivery()->getEncryptedMaterial());
        self::assertSame('user.invitation_delivery.confirmed', $auditEvidenceRepository->all()[0]->action());
        self::assertCount(1, $provider->invocations());
        self::assertInstanceOf(UserInvitationDelivered::class, $eventDispatcher->events()[0]);
        self::assertSame(DeliverUserInvitation::class, DeliverUserInvitationHandler::commandRegistration());
    }

    public function test_typed_retryable_and_permanent_outcomes_record_safe_terminal_states(): void
    {
        foreach (
            [
            [CredentialDeliveryOutcome::RETRYABLE_FAILURE, CredentialDeliveryStatus::RETRY_PENDING],
            [CredentialDeliveryOutcome::PERMANENT_FAILURE, CredentialDeliveryStatus::PERMANENT_FAILURE]
            ] as [$outcome, $expectedStatus]
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $grant = $this->grant();
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            self::assertTrue($activationGrantRepository->add($grant));
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();

            $this->handler(
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new RecordingCredentialDeliveryProvider([$outcome]),
                $eventDispatcher
            )->handle(CommandMessage::create($this->command($grant)));

            $delivery = $activationGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery();
            self::assertSame($expectedStatus, $delivery->getStatus());
            self::assertSame('user.invitation_delivery.failed', $auditEvidenceRepository->all()[0]->action());
            self::assertSame([], $eventDispatcher->events());
            if ($outcome === CredentialDeliveryOutcome::RETRYABLE_FAILURE) {
                self::assertSame(CredentialDeliveryFailure::RETRYABLE_PROVIDER, $delivery->getLastFailure());
                self::assertNotNull($delivery->getEncryptedMaterial());
            } else {
                self::assertSame(CredentialDeliveryFailure::PERMANENT_PROVIDER, $delivery->getLastFailure());
                self::assertNull($delivery->getEncryptedMaterial());
            }
        }
    }

    public function test_unexpected_provider_error_is_sanitized_as_retryable_without_command_failure(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($grant));
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new RecordingCredentialDeliveryProvider([new RuntimeException('secret vendor diagnostic')]),
            $eventDispatcher
        )->handle(CommandMessage::create($this->command($grant)));

        $delivery = $activationGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery();
        self::assertSame(CredentialDeliveryFailure::UNEXPECTED_PROVIDER, $delivery?->getLastFailure());
        self::assertSame([], $eventDispatcher->events());
        self::assertStringNotContainsString(
            'secret vendor diagnostic',
            print_r($delivery, true).var_export($delivery, true)
        );
    }

    public function test_provider_acceptance_followed_by_outcome_commit_failure_reuses_idempotency_identity(): void
    {
        $unitOfWork = new InMemoryUnitOfWork(failOnTransaction: 2);
        $grant = $this->grant();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider();
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            $provider,
            $eventDispatcher,
            new FixedClock(
                '2026-08-23T11:00:00+00:00',
                '2026-08-23T11:00:01+00:00',
                '2026-08-23T11:00:02+00:00',
                '2026-08-23T11:05:00+00:00',
                '2026-08-23T11:05:01+00:00',
                '2026-08-23T11:05:02+00:00'
            )
        );

        try {
            $handler->handle(CommandMessage::create($this->command($grant)));
            self::fail('The injected outcome commit failure was accepted.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Injected transaction failure.', $runtimeException->getMessage());
        }

        self::assertSame(
            CredentialDeliveryStatus::CLAIMED,
            $activationGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
        );
        $handler->handle(CommandMessage::create($this->command($grant)));

        self::assertCount(2, $provider->invocations());
        self::assertSame(
            $provider->invocations()[0]->getIdempotencyId(),
            $provider->invocations()[1]->getIdempotencyId()
        );
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        self::assertInstanceOf(UserInvitationDelivered::class, $eventDispatcher->events()[1]);
    }

    public function test_missing_stale_and_competing_claims_never_invoke_the_provider(): void
    {
        $provider = new RecordingCredentialDeliveryProvider();
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = $this->handler(
            new InMemoryActivationGrantRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $provider,
            $eventDispatcher
        );

        try {
            $handler->handle(CommandMessage::create(new DeliverUserInvitation(
                'Admin-42',
                UserId::generate(),
                ActivationDeliveryId::generate()
            )));
            self::fail('Missing work was accepted.');
        } catch (StateConflict) {
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork, replaceFailureOnCall: 1);
        self::assertTrue($activationGrantRepository->add($grant));
        try {
            $this->handler(
                $activationGrantRepository,
                new InMemoryAuditEvidenceRepository(),
                $unitOfWork,
                $provider
            )->handle(
                CommandMessage::create($this->command($grant))
            );
            self::fail('A competing claim loss was accepted.');
        } catch (StateConflict) {
        }

        self::assertSame([], $provider->invocations());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
    }

    public function test_concurrent_outcome_makes_the_provider_result_stale(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use (
            $activationGrantRepository,
            $grant
        ): void {
            $claimed = $activationGrantRepository->getLatestByUserId($grant->getUserId());
            $claimToken = $claimed?->getDelivery()->getClaimToken();
            self::assertNotNull($claimed);
            self::assertNotNull($claimToken);
            self::assertTrue($activationGrantRepository->replace(
                $claimed,
                $claimed->confirmDelivery($claimToken, new DateTimeImmutable('2026-08-23T11:00:30+00:00'))
            ));
        });

        $this->expectException(StateConflict::class);
        $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            $unitOfWork,
            $provider
        )->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_outcome_cas_loss_rolls_back_audit_and_surfaces_safe_failure(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork, replaceFailureOnCall: 2);
        self::assertTrue($activationGrantRepository->add($grant));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $provider = new RecordingCredentialDeliveryProvider();

        $this->expectException(StateConflict::class);
        try {
            $this->handler($activationGrantRepository, $auditEvidenceRepository, $unitOfWork, $provider)->handle(
                CommandMessage::create($this->command($grant))
            );
        } finally {
            self::assertCount(1, $provider->invocations());
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertSame(
                CredentialDeliveryStatus::CLAIMED,
                $activationGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
            );
        }
    }

    public function test_claim_lease_is_capped_at_expiry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant(expiresAt: '2026-08-23T11:01:00+00:00');
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        self::assertTrue($activationGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use (
            $activationGrantRepository,
            $grant,
            $unitOfWork
        ): void {
            $claimed = $activationGrantRepository->getLatestByUserId($grant->getUserId());
            self::assertFalse($unitOfWork->transactionActive);
            self::assertEquals($grant->getExpiresAt(), $claimed?->getDelivery()->getLeaseUntil());
        });

        $this->handler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            $unitOfWork,
            $provider
        )->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_command_and_success_event_round_trip_and_reject_missing_data(): void
    {
        $command = $this->command($this->grant());
        self::assertEquals($command, DeliverUserInvitation::fromArray($command->toArray()));
        self::assertSame('Admin-42', $command->getActorId());
        $event = new UserInvitationDelivered(
            $command->getActorId(),
            $command->getUserId(),
            $command->getActivationDeliveryId()
        );
        self::assertEquals($event, UserInvitationDelivered::fromArray($event->toArray()));
        self::assertSame($command->getActorId(), $event->getActorId());
        self::assertSame($command->getUserId(), $event->getUserId());
        self::assertSame($command->getActivationDeliveryId(), $event->getActivationDeliveryId());

        foreach (['actor_id', 'user_id', 'activation_delivery_id'] as $missing) {
            $commandData = $command->toArray();
            unset($commandData[$missing]);
            try {
                DeliverUserInvitation::fromArray($commandData);
                self::fail('Missing command data was accepted.');
            } catch (DomainException) {
            }

            $eventData = $event->toArray();
            unset($eventData[$missing]);
            try {
                UserInvitationDelivered::fromArray($eventData);
                self::fail('Missing event data was accepted.');
            } catch (DomainException) {
            }
        }

        self::addToAssertionCount(6);
    }

    private function grant(string $expiresAt = '2026-08-23T12:00:00+00:00'): ActivationGrant
    {
        return ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00'),
            new DateTimeImmutable($expiresAt),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:activate-once'
        );
    }

    private function command(ActivationGrant $grant): DeliverUserInvitation
    {
        return new DeliverUserInvitation(
            'Admin-42',
            $grant->getUserId(),
            $grant->getDelivery()->getId()
        );
    }

    private function handler(
        InMemoryActivationGrantRepository $activationGrantRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        ?RecordingCredentialDeliveryProvider $provider = null,
        ?InMemoryEventDispatcher $eventDispatcher = null,
        ?FixedClock $clock = null
    ): DeliverUserInvitationHandler {
        return new DeliverUserInvitationHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new PrefixInvitationDeliveryCipher(),
            $provider ?? new RecordingCredentialDeliveryProvider(),
            $clock ?? new FixedClock('2026-08-23T11:00:00+00:00'),
            $eventDispatcher ?? new InMemoryEventDispatcher()
        );
    }
}
