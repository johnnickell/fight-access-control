<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\EventSubscriber\PasswordResetDeliverySubscriber;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryConfirmed;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetRequested;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrant;
use Fight\AccessControl\Domain\AccessControl\User\RecoveryAuthority;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Application\Error\Exception\StateConflict;
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
use Fight\Test\AccessControl\Application\AccessControl\User\Repository\InMemoryUserRepository;
use Fight\Test\AccessControl\Domain\AccessControl\User\UserFixture;
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
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        self::assertTrue($passwordResetGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
            CredentialDeliveryInvocation $invocation
        ) use ($unitOfWork): void {
            self::assertFalse($unitOfWork->transactionActive);
            self::assertSame('password_reset', $invocation->getPurpose());
            self::assertSame('reset-once', $invocation->getCredential());
            self::assertSame('alice@example.test', $invocation->getEmail()->canonical());
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();

        $this->handler(
            $passwordResetGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $provider,
            $eventDispatcher
        )->handle(
            CommandMessage::create($command)
        );

        $stored = $passwordResetGrantRepository->getLatestByUserId($grant->getUserId());
        self::assertSame(2, $unitOfWork->transactions);
        self::assertSame(CredentialDeliveryStatus::DELIVERED, $stored->getDelivery()->getStatus());
        self::assertNull($stored->getDelivery()->getEncryptedMaterial());
        self::assertSame('user.password_reset_delivery.confirmed', $auditEvidenceRepository->all()[0]->action()->value);
        self::assertInstanceOf(PasswordResetDeliveryConfirmed::class, $eventDispatcher->events()[0]);
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
            $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
            self::assertTrue($passwordResetGrantRepository->add($grant));
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();

            $this->handler(
                $passwordResetGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new RecordingCredentialDeliveryProvider([$providerResult]),
                $eventDispatcher
            )->handle(CommandMessage::create($this->command($grant)));

            $delivery = $passwordResetGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery();
            self::assertSame($expectedStatus, $delivery?->getStatus());
            self::assertSame(
                'user.password_reset_delivery.failed',
                $auditEvidenceRepository->all()[0]->action()->value
            );
            self::assertSame([], $eventDispatcher->events());
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
        $eventDispatcher = new InMemoryEventDispatcher();
        try {
            $this->handler(
                new InMemoryPasswordResetGrants(),
                new InMemoryAuditEvidenceRepository(),
                new InMemoryUnitOfWork(),
                $provider,
                $eventDispatcher
            )->handle(CommandMessage::create(new DeliverPasswordReset(
                AnonymousId::generate(),
                UserId::generate(),
                PasswordResetDeliveryId::generate()
            )));
            self::fail('Missing reset delivery was accepted.');
        } catch (StateConflict) {
        }

        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork, replaceFailureOnCall: 1);
        self::assertTrue($passwordResetGrantRepository->add($grant));
        try {
            $this->handler(
                $passwordResetGrantRepository,
                new InMemoryAuditEvidenceRepository(),
                $unitOfWork,
                $provider
            )->handle(
                CommandMessage::create($this->command($grant))
            );
            self::fail('A competing reset-delivery claim was accepted.');
        } catch (StateConflict) {
        }

        self::assertSame([], $provider->invocations());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
    }

    public function test_concurrent_outcome_makes_provider_result_stale(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        self::assertTrue($passwordResetGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use (
            $passwordResetGrantRepository,
            $grant
        ): void {
            $claimed = $passwordResetGrantRepository->getLatestByUserId($grant->getUserId());
            $claimToken = $claimed?->getDelivery()->getClaimToken();
            self::assertNotNull($claimed);
            self::assertNotNull($claimToken);
            self::assertTrue($passwordResetGrantRepository->replace(
                $claimed,
                $claimed->confirmDelivery($claimToken, new DateTimeImmutable('2026-08-23T11:00:30+00:00'))
            ));
        });

        $this->expectException(StateConflict::class);
        $this->handler(
            $passwordResetGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            $unitOfWork,
            $provider
        )->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_outcome_cas_loss_preserves_claim_without_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant();
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork, replaceFailureOnCall: 2);
        self::assertTrue($passwordResetGrantRepository->add($grant));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);

        $this->expectException(StateConflict::class);
        try {
            $this->handler($passwordResetGrantRepository, $auditEvidenceRepository, $unitOfWork)->handle(
                CommandMessage::create($this->command($grant))
            );
        } finally {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertSame(
                CredentialDeliveryStatus::CLAIMED,
                $passwordResetGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
            );
        }
    }

    public function test_claim_lease_is_capped_at_expiry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant('2026-08-23T11:01:00+00:00');
        $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
        self::assertTrue($passwordResetGrantRepository->add($grant));
        $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use (
            $passwordResetGrantRepository,
            $grant
        ): void {
            self::assertEquals(
                $grant->getExpiresAt(),
                $passwordResetGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery()->getLeaseUntil()
            );
        });

        $this->handler(
            $passwordResetGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            $unitOfWork,
            $provider
        )->handle(
            CommandMessage::create($this->command($grant))
        );
    }

    public function test_command_round_trips_and_rejects_missing_data(): void
    {
        $command = $this->command($this->grant());
        self::assertEquals($command, DeliverPasswordReset::fromArray($command->toArray()));
        self::assertEquals(AnonymousId::generate(), $command->getActorId());
        self::assertSame($command->getUserId(), $command->getUserId());
        self::assertSame($command->getPasswordResetDeliveryId(), $command->getPasswordResetDeliveryId());
        self::assertArrayNotHasKey('credential', $command->toArray());
        self::assertArrayNotHasKey('ciphertext', $command->toArray());

        foreach (['actor_kind', 'actor_id', 'user_id', 'password_reset_delivery_id'] as $missing) {
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

    public function test_outer_failure_preserves_delivery_phase_and_fresh_worker_recovery(): void
    {
        foreach (['claim', 'audit', 'outcome', 'publication'] as $phase) {
            $unitOfWork = new InMemoryUnitOfWork();
            $grant = $this->grant();
            $passwordResetGrantRepository = new InMemoryPasswordResetGrants($unitOfWork);
            self::assertTrue($passwordResetGrantRepository->add($grant));
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
                $unitOfWork,
                failAfterSave: $phase === 'audit'
            );
            $primary = new RuntimeException('Synthetic operation detail. reset-once ciphertext:reset-once');
            $unitOfWork->beforeCommit = static function () use ($phase, $unitOfWork, $primary): void {
                if (
                    ($phase === 'claim' && $unitOfWork->transactions === 1)
                    || ($phase === 'outcome' && $unitOfWork->transactions === 2)
                ) {
                    throw $primary;
                }
            };
            $attempts = [];
            $eventDispatcher = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $primary): void {
                $attempts[] = $event;
                if ($event instanceof PasswordResetDeliveryConfirmed) {
                    throw $primary;
                }

                throw new RuntimeException('Synthetic notification failure.');
            });
            $provider = new RecordingCredentialDeliveryProvider();
            $caught = null;
            try {
                $this->handler(
                    $passwordResetGrantRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    $provider,
                    $eventDispatcher
                )->handle(
                    CommandMessage::create($this->command($grant))
                );
            } catch (RuntimeException $exception) {
                $caught = $exception;
            }

            $expected = $primary;
            if ($phase === 'audit') {
                $expected = $auditEvidenceRepository->failure();
            }

            self::assertSame($expected, $caught);
            $published = $phase === 'publication';
            $stored = $passwordResetGrantRepository->getLatestByUserId($grant->getUserId());
            $status = CredentialDeliveryStatus::CLAIMED;
            if ($phase === 'claim') {
                $status = CredentialDeliveryStatus::PENDING;
            } elseif ($published) {
                $status = CredentialDeliveryStatus::DELIVERED;
            }

            self::assertSame($status, $stored->getDelivery()->getStatus());
            self::assertSame(!$published, $stored->getDelivery()->hasRecoverableMaterial());
            self::assertSame($grant->getDelivery()->getId(), $stored->getDelivery()->getId());
            self::assertCount((int) $published, $auditEvidenceRepository->all());
            self::assertCount((int) ($phase !== 'claim'), $provider->invocations());
            self::assertCount(1 + (int) $published, $attempts);
            $failure = $attempts[array_key_last($attempts)];
            self::assertInstanceOf(CommandFailedEvent::class, $failure);
            self::assertSame('Password reset delivery failed.', $failure->getErrorMessage());
            self::assertStringNotContainsString('reset-once', serialize($failure->toArray()));
            self::assertFalse($unitOfWork->transactionActive);
            $unitOfWork->beforeCommit = null;
            $due = $passwordResetGrantRepository->findDue(new DateTimeImmutable('2026-08-23T11:06:00+00:00'), 10);
            self::assertCount((int) !$published, $due);
            if (!$published) {
                self::assertSame($grant->getDelivery()->getId(), $due[0]->getDeliveryId());
                new DeliverPasswordResetHandler(
                    $passwordResetGrantRepository,
                    new InMemoryAuditEvidenceRepository($unitOfWork),
                    $unitOfWork,
                    new PrefixPasswordResetDeliveryCipher(),
                    $provider,
                    new FixedClock('2026-08-23T11:06:00+00:00'),
                    new InMemoryEventDispatcher(),
                    $this->users($passwordResetGrantRepository, $unitOfWork)
                )->handle(CommandMessage::create($this->command($grant)));
                self::assertSame(
                    CredentialDeliveryStatus::DELIVERED,
                    $passwordResetGrantRepository->getLatestByUserId($grant->getUserId())->getDelivery()->getStatus()
                );
            }
        }
    }

    private function grant(string $expiresAt = '2026-08-23T12:00:00+00:00'): PasswordResetGrant
    {
        return PasswordResetGrant::issue(
            UserId::generate(),
            PasswordResetCredential::fromString('reset-once'),
            new DateTimeImmutable('2026-08-23T11:00:00+00:00'),
            new DateTimeImmutable($expiresAt),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext:reset-once',
            new RecoveryAuthority(1, 0)
        );
    }

    private function users(
        InMemoryPasswordResetGrants $passwordResetGrantRepository,
        InMemoryUnitOfWork $unitOfWork
    ): InMemoryUserRepository {
        $userRepository = new InMemoryUserRepository($unitOfWork);
        foreach ($passwordResetGrantRepository->all() as $grant) {
            if ($userRepository->getById($grant->getUserId()) === null) {
                $userRepository->add(UserFixture::withIdAndAuthenticationVersion(
                    $grant->getUserId(),
                    $grant->getDelivery()->getEmail()->toString(),
                    UserState::ACTIVE,
                    $grant->getAuthority()->getAuthenticationVersion()
                ));
            }
        }

        return $userRepository;
    }

    private function command(PasswordResetGrant $grant): DeliverPasswordReset
    {
        return new DeliverPasswordReset(
            AnonymousId::generate(),
            $grant->getUserId(),
            $grant->getDelivery()->getId()
        );
    }

    private function handler(
        InMemoryPasswordResetGrants $passwordResetGrantRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        InMemoryUnitOfWork $unitOfWork,
        ?RecordingCredentialDeliveryProvider $provider = null,
        ?InMemoryEventDispatcher $eventDispatcher = null
    ): DeliverPasswordResetHandler {
        return new DeliverPasswordResetHandler(
            $passwordResetGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new PrefixPasswordResetDeliveryCipher(),
            $provider ?? new RecordingCredentialDeliveryProvider(),
            new FixedClock('2026-08-23T11:00:00+00:00'),
            $eventDispatcher ?? new InMemoryEventDispatcher(),
            $this->users($passwordResetGrantRepository, $unitOfWork)
        );
    }
}
