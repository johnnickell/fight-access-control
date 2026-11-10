<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fiber;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\RetryInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\EventSubscriber\InvitationDeliverySubscriber;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\InvitationDeliveryCipher;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryRetryRequested;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditSubject;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
use Fight\Common\Application\Error\Exception\StateConflict;
use Fight\Common\Application\Messaging\Command\CommandBus;
use Fight\Common\Application\Messaging\Event\EventDispatchFailed;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Utility\ClassName;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Repository\InMemoryActivationGrantRepository;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\FixedCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service\PrefixInvitationDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(RetryInvitationDeliveryHandler::class)]
#[CoversClass(ActivationGrant::class)]
#[CoversClass(InvitationDeliveryRetryRequested::class)]
#[CoversClass(RetryInvitationDelivery::class)]
final class RetryInvitationDeliveryHandlerTest extends TestCase
{
    public function test_that_exact_expiry_rejects_without_mutating_persisted_retry_work(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock($grant->getExpiresAt()),
            $eventDispatcher
        );

        $rejection = null;
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $grant->getUserId()
            )));
        } catch (StateConflict $stateConflict) {
            $rejection = $stateConflict;
        }

        self::assertInstanceOf(
            StateConflict::class,
            $rejection,
            'At exact expiry retry must reject, not return normally after persisting retry state and audit.'
        );
        self::assertSame('The activation delivery work is no longer retryable.', $rejection->getMessage());
        self::assertEquals($grant, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
        self::assertSame([], $auditEvidenceRepository->all());
        self::assertCount(1, $eventDispatcher->events());
        self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
    }

    public function test_that_it_publishes_a_retry_request_after_the_durable_commit(): void
    {
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->persistedFailedGrant($activationGrantRepository);
        $unitOfWork = new InMemoryUnitOfWork();
        $eventDispatcher = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );

        $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
            UserId::generate(),
            $activationGrant->getUserId()
        )));

        self::assertSame(RetryInvitationDelivery::class, RetryInvitationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        $retryRequested = $activationGrantRepository->getLatestByUserId($activationGrant->getUserId());
        self::assertTrue($retryRequested?->getDelivery()->isRetryable());
        self::assertSame('ciphertext', $retryRequested->getDelivery()->getEncryptedMaterial()?->reveal());
        self::assertSame(
            'user.invitation_delivery.retry_requested',
            $auditEvidenceRepository->all()[0]->action()->value
        );
        self::assertInstanceOf(InvitationDeliveryRetryRequested::class, $eventDispatcher->events()[0]);
    }

    public function test_that_terminal_delivery_work_does_not_publish_a_retry_request(): void
    {
        $activationGrantRepository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->grant();
        self::assertTrue($activationGrantRepository->add($activationGrant));
        $token = CredentialDeliveryClaimToken::generate();
        $at = new DateTimeImmutable('2026-08-18T12:00:00+00:00');
        $claimed = $activationGrant->claimDelivery($token, $at, $at->modify('+5 minutes'));
        self::assertTrue($activationGrantRepository->replace($activationGrant, $claimed));
        $activationGrant = $claimed->confirmDelivery($token, $at);
        self::assertTrue($activationGrantRepository->replace($claimed, $activationGrant));
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );

        $this->expectException(StateConflict::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_missing_delivery_work_does_not_publish_a_retry_request(): void
    {
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            new InMemoryActivationGrantRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );

        $this->expectException(StateConflict::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                UserId::generate()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_prevents_retry_request_publication(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $activationGrant = $this->persistedFailedGrant($activationGrantRepository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertEquals(
                $activationGrant,
                $activationGrantRepository->getLatestByUserId($activationGrant->getUserId())
            );
            self::assertCount(0, $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_that_a_concurrent_replacement_before_retry_cas_has_no_audit_or_success_event(): void
    {
        $successor = ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $userId = UserId::generate(),
            ActivationCredential::fromString('activate-new'),
            new DateTimeImmutable('2026-08-19T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-26T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'new-ciphertext'
        );
        $activationGrantRepository = new InMemoryActivationGrantRepository(
            beforeReplace: static function (
                InMemoryActivationGrantRepository $activationGrantRepository,
                ActivationGrant $predecessor
            ) use ($successor): void {
                self::assertTrue($activationGrantRepository->replaceWithSuccessor(
                    $predecessor,
                    $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
                    $successor
                ));
            },
            beforeReplaceOnCall: 3
        );
        $activationGrant = $this->persistedFailedGrant($activationGrantRepository, $userId);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            new InMemoryUnitOfWork(),
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );

        $this->expectException(StateConflict::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            self::assertSame($successor, $activationGrantRepository->getLatestByUserId($activationGrant->getUserId()));
        }
    }

    public function test_boundary_admission_with_and_without_the_real_subscriber(): void
    {
        foreach ([false, true] as $subscribed) {
            foreach ([-1, 0, 1] as $offset) {
                $unitOfWork = new InMemoryUnitOfWork();
                $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
                $grant = $this->persistedFailedGrant($activationGrantRepository);
                $at = $grant->getExpiresAt()->modify(sprintf('%+d seconds', $offset));
                $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
                $eventDispatcher = new SimpleEventDispatcher();
                $facts = [];
                $eventDispatcher->addHandler(
                    ClassName::underscore(InvitationDeliveryRetryRequested::class),
                    static function () use (&$facts, $unitOfWork, $auditEvidenceRepository): void {
                        self::assertFalse($unitOfWork->transactionActive);
                        self::assertCount(1, $auditEvidenceRepository->all());
                        $facts[] = 'retry';
                    }
                );
                $cipher = $this->createMock(InvitationDeliveryCipher::class);
                $cipher->expects(self::never())->method('encrypt');
                $cipher->expects($subscribed && $offset < 0 ? self::once() : self::never())
                    ->method('decrypt')->willReturn('activate-once');
                $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
                    CredentialDeliveryInvocation $invocation
                ) use (
                    $unitOfWork,
                    $activationGrantRepository,
                    $grant
                ): void {
                    self::assertFalse($unitOfWork->transactionActive);
                    self::assertSame(2, $unitOfWork->transactions);
                    self::assertSame($grant->getDelivery()->getId()->toString(), $invocation->getIdempotencyId());
                    self::assertSame(
                        CredentialDeliveryStatus::CLAIMED,
                        $activationGrantRepository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
                    );
                });
                if ($subscribed) {
                    $worker = new DeliverUserInvitationHandler(
                        $activationGrantRepository,
                        $auditEvidenceRepository,
                        $unitOfWork,
                        $cipher,
                        $provider,
                        new FixedClock($at),
                        $eventDispatcher
                    );
                    $commandBus = $this->createMock(CommandBus::class);
                    $commandBus->expects($offset < 0 ? self::once() : self::never())->method('execute')
                        ->willReturnCallback(static function (DeliverUserInvitation $command) use ($worker): void {
                            $worker->handle(CommandMessage::create($command));
                        });
                    $eventDispatcher->register(new InvitationDeliverySubscriber($commandBus));
                }

                $handler = new RetryInvitationDeliveryHandler(
                    $activationGrantRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    new FixedClock($at),
                    $eventDispatcher
                );
                $failure = null;
                try {
                    $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                        UserId::generate(),
                        $grant->getUserId()
                    )));
                } catch (Throwable $throwable) {
                    $failure = $throwable;
                }

                $stored = $activationGrantRepository->getLatestByUserId($grant->getUserId());
                if ($offset >= 0) {
                    self::assertInstanceOf(StateConflict::class, $failure);
                    self::assertSame('The activation delivery work is no longer retryable.', $failure->getMessage());
                    self::assertEquals($grant, $stored);
                    self::assertSame([], $auditEvidenceRepository->all());
                    self::assertSame([], $facts);
                    self::assertSame([], $provider->invocations());
                    continue;
                }

                self::assertNull($failure);
                self::assertSame(['retry'], $facts);
                self::assertCount($subscribed ? 1 : 0, $provider->invocations());
                self::assertSame(
                    $subscribed ? CredentialDeliveryStatus::DELIVERED : CredentialDeliveryStatus::PENDING,
                    $stored?->getDelivery()->getStatus()
                );
            }
        }
    }

    public function test_fractional_and_timezone_equivalent_expiry_is_not_rounded(): void
    {
        foreach (['2026-08-25T14:00:00.123455+02:00', '2026-08-25T07:00:00.123456-05:00'] as $time) {
            $unitOfWork = new InMemoryUnitOfWork();
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->persistedFailedGrant(
                $activationGrantRepository,
                expiresAt: new DateTimeImmutable('2026-08-25T12:00:00.123456Z')
            );
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();
            $at = new DateTimeImmutable($time);
            $handler = new RetryInvitationDeliveryHandler(
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new FixedClock($at),
                $eventDispatcher
            );
            try {
                $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                    UserId::generate(),
                    $grant->getUserId()
                )));
                self::assertLessThan($grant->getExpiresAt(), $at);
                self::assertCount(1, $auditEvidenceRepository->all());
            } catch (StateConflict $exception) {
                self::assertEquals($grant->getExpiresAt(), $at);
                self::assertSame('The activation delivery work is no longer retryable.', $exception->getMessage());
                self::assertEquals($grant, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
                self::assertSame([], $auditEvidenceRepository->all());
            }
        }
    }

    public function test_clock_is_sampled_after_the_transactional_read_even_if_the_read_crosses_expiry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $storedActivationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($storedActivationGrantRepository);
        $time = $grant->getExpiresAt()->modify('-1 second');
        $readCompleted = false;
        $activationGrantRepository = $this->createMock(ActivationGrantRepository::class);
        $activationGrantRepository->expects(self::once())->method('getLatestByUserId')->with($grant->getUserId())
            ->willReturnCallback(
                static function () use (&$time, &$readCompleted, $grant, $unitOfWork): ActivationGrant {
                    self::assertTrue($unitOfWork->transactionActive);
                    $time = $grant->getExpiresAt();
                    $readCompleted = true;

                    return $grant;
                }
            );
        $activationGrantRepository->expects(self::never())->method('replace');
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::once())->method('now')->willReturnCallback(
            static function () use (&$time, &$readCompleted, $unitOfWork): DateTimeImmutable {
                self::assertTrue($readCompleted);
                self::assertTrue($unitOfWork->transactionActive);

                return $time;
            }
        );
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $clock,
            new InMemoryEventDispatcher()
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $grant->getUserId()
            )));
            self::fail('A read crossing expiry must not reuse pre-transaction time.');
        } catch (StateConflict) {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertEquals($grant, $storedActivationGrantRepository->getLatestByUserId($grant->getUserId()));
        }
    }

    public function test_success_preserves_authority_identity_and_delivery_history(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedClock('2026-08-25T11:59:59Z'),
            new InMemoryEventDispatcher()
        );
        $handler->handle(CommandMessage::create(new RetryInvitationDelivery(UserId::generate(), $grant->getUserId())));

        $stored = $activationGrantRepository->getLatestByUserId($grant->getUserId());
        self::assertNotNull($stored);
        self::assertSame($grant->getRevision() + 1, $stored->getRevision());
        self::assertTrue($grant->getId()->equals($stored->getId()));
        self::assertTrue($grant->getUserId()->equals($stored->getUserId()));
        self::assertSame($grant->getCredentialHash(), $stored->getCredentialHash());
        self::assertEquals($grant->getExpiresAt(), $stored->getExpiresAt());
        $before = $grant->getDelivery();
        $after = $stored->getDelivery();
        self::assertTrue($before->getId()->equals($after->getId()));
        self::assertTrue($before->getUserId()->equals($after->getUserId()));
        self::assertEquals($before->getExpiresAt(), $after->getExpiresAt());
        self::assertEquals($before->getEmail(), $after->getEmail());
        self::assertSame($before->getEncryptedMaterial()?->reveal(), $after->getEncryptedMaterial()?->reveal());
        self::assertSame($before->getAttemptCount(), $after->getAttemptCount());
        self::assertEquals($before->getLastAttemptAt(), $after->getLastAttemptAt());
        self::assertEquals($before->getLastOutcomeAt(), $after->getLastOutcomeAt());
        self::assertSame($before->getLastFailure(), $after->getLastFailure());
        self::assertEquals($before->getLastAttemptAt(), $after->getNextAttemptAt());
        self::assertSame(CredentialDeliveryStatus::PENDING, $after->getStatus());
        self::assertCount(1, $activationGrantRepository->all());
    }

    public function test_non_retry_pending_and_terminal_authority_reject_without_changes(): void
    {
        foreach (['pending', 'claimed', 'delivered', 'permanent', 'consumed', 'revoked', 'expired'] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant();
            self::assertTrue($activationGrantRepository->add($grant));
            $at = new DateTimeImmutable('2026-08-18T12:00:00Z');
            $token = CredentialDeliveryClaimToken::generate();
            if (in_array($state, ['claimed', 'delivered', 'permanent'], true)) {
                $claimed = $grant->claimDelivery($token, $at, $at->modify('+5 minutes'));
                self::assertTrue($activationGrantRepository->replace($grant, $claimed));
                $grant = $claimed;
            }

            $next = match ($state) {
                'delivered' => $grant->confirmDelivery($token, $at),
                'permanent' => $grant->failDeliveryPermanently($token, $at),
                'consumed' => $grant->consume($at),
                'revoked' => $grant->revoke($at),
                'expired' => $grant->expireDeliveryAt($grant->getExpiresAt()),
                default => $grant
            };
            if ($next !== $grant) {
                self::assertTrue($activationGrantRepository->replace($grant, $next));
            }

            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $eventDispatcher = new InMemoryEventDispatcher();
            $handler = new RetryInvitationDeliveryHandler(
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new FixedClock('2026-08-19T12:00:00Z'),
                $eventDispatcher
            );
            try {
                $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                    UserId::generate(),
                    $grant->getUserId()
                )));
                self::fail('Ineligible work accepted: '.$state);
            } catch (StateConflict $exception) {
                self::assertSame('The activation delivery work is no longer retryable.', $exception->getMessage());
                self::assertEquals($next, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
                self::assertSame([], $auditEvidenceRepository->all());
                self::assertCount(1, $eventDispatcher->events());
                self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
            }
        }
    }

    public function test_commit_failure_rolls_back_both_persisted_retry_and_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork(failOnTransaction: 1);
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $grant->getUserId()
            )));
            self::fail('Commit failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Injected transaction failure.', $runtimeException->getMessage());
            self::assertEquals($grant, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $eventDispatcher->events());
            self::assertInstanceOf(CommandFailedEvent::class, $eventDispatcher->events()[0]);
        }
    }

    public function test_failure_notification_cannot_replace_the_expected_expiry_rejection(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $command = new RetryInvitationDelivery(UserId::generate(), $grant->getUserId());
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $notified = null;
        $eventDispatcher = new InMemoryEventDispatcher(
            static function (CommandFailedEvent $event) use (&$notified): void {
                $notified = $event;

                throw new RuntimeException('Secondary notification failure.');
            }
        );
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock($grant->getExpiresAt()),
            $eventDispatcher
        );
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('Expiry was accepted.');
        } catch (StateConflict $stateConflict) {
            self::assertSame(
                'The activation delivery work is no longer retryable.',
                $stateConflict->getMessage()
            );
            self::assertInstanceOf(CommandFailedEvent::class, $notified);
            self::assertSame($command, $notified->getCommand());
            self::assertSame('Invitation delivery retry failed.', $notified->getErrorMessage());
            self::assertEquals($grant, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
            self::assertSame([], $auditEvidenceRepository->all());
        }
    }

    public function test_publication_failure_keeps_committed_retry_and_original_throwable(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $primary = new RuntimeException('Publication failed.');
        $eventDispatcher = new InMemoryEventDispatcher(
            static function (Event $event) use ($primary, $unitOfWork): void {
                self::assertFalse($unitOfWork->transactionActive);
                if ($event instanceof InvitationDeliveryRetryRequested) {
                    throw $primary;
                }

                throw new RuntimeException('Secondary notification failure.');
            }
        );
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $eventDispatcher
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $grant->getUserId()
            )));
            self::fail('Publication failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
            self::assertEquals(
                $grant->requestDeliveryRetry(),
                $activationGrantRepository->getLatestByUserId($grant->getUserId())
            );
            self::assertCount(1, $auditEvidenceRepository->all());
        }
    }

    public function test_competing_commits_win_without_the_losing_retry_undoing_them(): void
    {
        foreach (['resend', 'consume', 'revoke', 'expiry', 'claim', 'retry'] as $winner) {
            $unitOfWork = new InMemoryUnitOfWork();
            $activationGrantRepository = new InMemoryActivationGrantRepository(
                $unitOfWork,
                beforeReplace: static function () use ($unitOfWork): void {
                    $unitOfWork->suspendBeforeFirstWrite();
                },
                beforeReplaceOnCall: 3
            );
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $grant = $this->persistedFailedGrant(
                $activationGrantRepository,
                auditEvidenceRepository: $auditEvidenceRepository
            );
            $loserEventDispatcher = new InMemoryEventDispatcher();
            $handler = new RetryInvitationDeliveryHandler(
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new FixedClock('2026-08-19T12:00:00Z'),
                $loserEventDispatcher
            );
            $loser = new Fiber(static function () use ($handler, $grant): void {
                $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                    UserId::generate(),
                    $grant->getUserId()
                )));
            });
            $loser->start();
            self::assertTrue($loser->isSuspended());
            if ($winner === 'retry') {
                new RetryInvitationDeliveryHandler(
                    $activationGrantRepository,
                    $auditEvidenceRepository,
                    $unitOfWork,
                    new FixedClock('2026-08-19T12:00:00Z'),
                    new InMemoryEventDispatcher()
                )->handle(CommandMessage::create(new RetryInvitationDelivery(UserId::generate(), $grant->getUserId())));
            } else {
                $unitOfWork->commitTransactional(
                    static function () use (
                        $activationGrantRepository,
                        $grant,
                        $winner
                    ): void {
                        $at = new DateTimeImmutable('2026-08-19T12:00:00Z');
                        if ($winner === 'resend') {
                            $successor = ActivationGrant::issue(
                                ActivationOrigin::PRIVATE_INVITATION,
                                $grant->getUserId(),
                                ActivationCredential::fromString('new-authority'),
                                $at,
                                $at->modify('+7 days'),
                                $grant->getDelivery()->getEmail(),
                                'new-material'
                            );
                            self::assertTrue(
                                $activationGrantRepository->replaceWithSuccessor(
                                    $grant,
                                    $grant->revoke($at),
                                    $successor
                                )
                            );
                        } else {
                            $replacement = match ($winner) {
                                'consume' => $grant->consume($at),
                                'revoke' => $grant->revoke($at),
                                'expiry' => $grant->expireDeliveryAt($grant->getExpiresAt()),
                                'claim' => $grant->claimDelivery(
                                    CredentialDeliveryClaimToken::generate(),
                                    $at,
                                    $at->modify('+5 minutes')
                                )
                            };
                            self::assertTrue($activationGrantRepository->replace($grant, $replacement));
                        }
                    }
                );
            }

            $committedWinner = $activationGrantRepository->getLatestByUserId($grant->getUserId());
            $winnerAudit = $auditEvidenceRepository->all();
            try {
                $loser->resume();
                self::fail('Stale retry overwrote '.$winner);
            } catch (StateConflict $exception) {
                self::assertSame('The activation delivery generation changed concurrently.', $exception->getMessage());
                self::assertSame($committedWinner, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
                self::assertSame($winnerAudit, $auditEvidenceRepository->all());
                // Preserve the prior committed failure record as well as a winning audited retry.
                self::assertCount($winner === 'retry' ? 2 : 1, $winnerAudit);
                self::assertCount(1, $loserEventDispatcher->events());
                self::assertInstanceOf(CommandFailedEvent::class, $loserEventDispatcher->events()[0]);
                self::assertFalse($activationGrantRepository->replace($grant, $grant->requestDeliveryRetry()));
            }
        }
    }

    public function test_retry_first_keeps_later_lifecycle_and_claim_successors_authoritative(): void
    {
        foreach (['consume', 'revoke', 'expiry', 'claim', 'resend'] as $next) {
            $unitOfWork = new InMemoryUnitOfWork();
            $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->persistedFailedGrant($activationGrantRepository);
            $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
            $handler = new RetryInvitationDeliveryHandler(
                $activationGrantRepository,
                $auditEvidenceRepository,
                $unitOfWork,
                new FixedClock('2026-08-19T12:00:00Z'),
                new InMemoryEventDispatcher()
            );
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $grant->getUserId()
            )));
            $current = $activationGrantRepository->getLatestByUserId($grant->getUserId());
            self::assertNotNull($current);
            $at = new DateTimeImmutable('2026-08-19T12:00:00Z');
            if ($next === 'resend') {
                $successor = ActivationGrant::issue(
                    ActivationOrigin::PRIVATE_INVITATION,
                    $grant->getUserId(),
                    ActivationCredential::fromString('new-authority'),
                    $at,
                    $at->modify('+7 days'),
                    $grant->getDelivery()->getEmail(),
                    'new-material'
                );
                self::assertTrue(
                    $activationGrantRepository->replaceWithSuccessor($current, $current->revoke($at), $successor)
                );
            } else {
                $successor = match ($next) {
                    'consume' => $current->consume($at),
                    'revoke' => $current->revoke($at),
                    'expiry' => $current->expireDeliveryAt($current->getExpiresAt()),
                    'claim' => $current->claimDelivery(
                        CredentialDeliveryClaimToken::generate(),
                        $at,
                        $at->modify('+5 minutes')
                    )
                };
                self::assertTrue($activationGrantRepository->replace($current, $successor));
            }

            self::assertFalse($activationGrantRepository->replace($grant, $grant->requestDeliveryRetry()));
            self::assertSame($successor, $activationGrantRepository->getLatestByUserId($grant->getUserId()));
            self::assertCount(1, $auditEvidenceRepository->all());
        }
    }

    public function test_expiry_after_admission_rejects_subscriber_claim_but_keeps_committed_retry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new SimpleEventDispatcher();
        $clock = new FixedClock($grant->getExpiresAt()->modify('-1 second'), $grant->getExpiresAt());
        $cipher = $this->createMock(InvitationDeliveryCipher::class);
        $cipher->expects(self::never())->method('decrypt');
        $provider = new RecordingCredentialDeliveryProvider();
        $worker = new DeliverUserInvitationHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $cipher,
            $provider,
            $clock,
            $eventDispatcher
        );
        $commandBus = $this->createMock(CommandBus::class);
        $commandBus->expects(self::once())->method('execute')->willReturnCallback(
            static function (DeliverUserInvitation $command) use ($worker): void {
                $worker->handle(CommandMessage::create($command));
            }
        );
        $eventDispatcher->register(new InvitationDeliverySubscriber($commandBus));
        $handler = new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $clock,
            $eventDispatcher
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                UserId::generate(),
                $grant->getUserId()
            )));
            self::fail('The expired subscriber claim was accepted.');
        } catch (EventDispatchFailed) {
            self::assertSame(2, $clock->calls());
            self::assertEquals(
                $grant->requestDeliveryRetry(),
                $activationGrantRepository->getLatestByUserId($grant->getUserId())
            );
            self::assertCount(1, $auditEvidenceRepository->all());
            self::assertSame(
                'user.invitation_delivery.retry_requested',
                $auditEvidenceRepository->all()[0]->action()->value
            );
            self::assertSame([], $provider->invocations());
        }
    }

    public function test_expired_retry_requires_explicit_resend_with_fresh_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $activationGrantRepository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($activationGrantRepository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $eventDispatcher = new InMemoryEventDispatcher();
        $clock = new FixedClock($grant->getExpiresAt());
        $resend = new ResendInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedCredentialGenerator('fresh-authority'),
            new PrefixInvitationDeliveryCipher(),
            $clock,
            $eventDispatcher
        );
        $resend->handle(CommandMessage::create(new ResendInvitationDelivery(UserId::generate(), $grant->getUserId())));

        $successor = $activationGrantRepository->getLatestByUserId($grant->getUserId());
        self::assertNotNull($successor);
        self::assertFalse($grant->getId()->equals($successor->getId()));
        self::assertFalse($grant->getDelivery()->getId()->equals($successor->getDelivery()->getId()));
        self::assertNotSame($grant->getCredentialHash(), $successor->getCredentialHash());
        self::assertGreaterThan($grant->getExpiresAt(), $successor->getExpiresAt());
        self::assertTrue($activationGrantRepository->getById($grant->getId())?->isRevoked());
        self::assertFalse($activationGrantRepository->replace($grant, $grant->requestDeliveryRetry()));
        self::assertSame($successor, $activationGrantRepository->getLatestByUserId($grant->getUserId()));

        $token = CredentialDeliveryClaimToken::generate();
        $at = $grant->getExpiresAt();
        $claimed = $successor->claimDelivery($token, $at, $at->modify('+5 minutes'));
        self::assertTrue($activationGrantRepository->replace($successor, $claimed));
        $failed = $claimed->failDelivery($token, $at, CredentialDeliveryFailure::RETRYABLE_PROVIDER);
        self::assertTrue($activationGrantRepository->replace($claimed, $failed));
        new RetryInvitationDeliveryHandler(
            $activationGrantRepository,
            $auditEvidenceRepository,
            $unitOfWork,
            $clock,
            $eventDispatcher
        )->handle(
            CommandMessage::create(new RetryInvitationDelivery(UserId::generate(), $grant->getUserId()))
        );
        self::assertEquals(
            $failed->requestDeliveryRetry(),
            $activationGrantRepository->getLatestByUserId($grant->getUserId())
        );
        self::assertTrue($activationGrantRepository->getById($grant->getId())->isRevoked());
        self::assertCount(2, $auditEvidenceRepository->all());
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new RetryInvitationDelivery(UserId::generate(), UserId::generate());
        $event = new InvitationDeliveryRetryRequested(
            $command->getActorId(),
            $command->getUserId(),
            ActivationDeliveryId::generate()
        );

        self::assertEquals($command, RetryInvitationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, InvitationDeliveryRetryRequested::fromArray($event->toArray()));
        self::assertSame($command->getActorId(), $event->getActorId());
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
            ActivationOrigin::PRIVATE_INVITATION,
            UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
    }

    private function persistedFailedGrant(
        InMemoryActivationGrantRepository $activationGrantRepository,
        ?UserId $userId = null,
        ?DateTimeImmutable $expiresAt = null,
        ?InMemoryAuditEvidenceRepository $auditEvidenceRepository = null
    ): ActivationGrant {
        $activationGrant = ActivationGrant::issue(
            ActivationOrigin::PRIVATE_INVITATION,
            $userId ?? UserId::generate(),
            ActivationCredential::fromString('activate-once'),
            new DateTimeImmutable('2026-08-18T12:00:00+00:00'),
            $expiresAt ?? new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            EmailAddress::fromString('alice@example.test'),
            'ciphertext'
        );
        self::assertTrue($activationGrantRepository->add($activationGrant));
        $token = CredentialDeliveryClaimToken::generate();
        $at = new DateTimeImmutable('2026-08-18T12:00:00+00:00');
        $claimed = $activationGrant->claimDelivery($token, $at, $at->modify('+5 minutes'));
        self::assertTrue($activationGrantRepository->replace($activationGrant, $claimed));
        $failed = $claimed->failDelivery($token, $at, CredentialDeliveryFailure::UNEXPECTED_PROVIDER);
        self::assertTrue($activationGrantRepository->replace($claimed, $failed));
        $auditEvidenceRepository?->add(AuditEvidence::record(
            UserId::generate(),
            new AuditSubject($failed->getUserId()),
            AuditAction::INVITATION_DELIVERY_FAILED,
            $at,
            new AuditGrantOutcome(
                AuditGrantState::fromGrant($claimed),
                AuditGrantState::fromGrant($failed),
                $failed->getDelivery()->getStatus(),
                $failed->getDelivery()->getLastFailure()
            ),
            null
        ));

        return $failed;
    }
}
