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
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Messaging\Event\Sync\SimpleEventDispatcher;
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
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $audit,
            $unitOfWork,
            new FixedClock($grant->getExpiresAt()),
            $events
        );

        $rejection = null;
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
        } catch (ActivationDeliveryNotRetryableException $activationDeliveryNotRetryableException) {
            $rejection = $activationDeliveryNotRetryableException;
        }

        self::assertInstanceOf(
            ActivationDeliveryNotRetryableException::class,
            $rejection,
            'At exact expiry retry must reject, not return normally after persisting retry state and audit.'
        );
        self::assertSame('The activation delivery work is no longer retryable.', $rejection->getMessage());
        self::assertEquals($grant, $repository->getLatestByUserId($grant->getUserId()));
        self::assertSame([], $audit->all());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
    }

    public function test_that_it_publishes_a_retry_request_after_the_durable_commit(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->persistedFailedGrant($repository);
        $unitOfWork = new InMemoryUnitOfWork();
        $events = new InMemoryEventDispatcher(static function () use ($unitOfWork): void {
            self::assertTrue($unitOfWork->transactionCompleted);
        });
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );

        $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
            'Admin-42',
            $activationGrant->getUserId()
        )));

        self::assertSame(RetryInvitationDelivery::class, RetryInvitationDeliveryHandler::commandRegistration());
        self::assertSame(1, $unitOfWork->transactions);
        $retryRequested = $repository->getLatestByUserId($activationGrant->getUserId());
        self::assertTrue($retryRequested?->getDelivery()->isRetryable());
        self::assertSame('ciphertext', $retryRequested->getDelivery()->getEncryptedMaterial()?->reveal());
        self::assertSame('user.invitation_delivery.retry_requested', $auditEvidenceRepository->all()[0]->action());
        self::assertInstanceOf(InvitationDeliveryRetryRequested::class, $events->events()[0]);
    }

    public function test_that_terminal_delivery_work_does_not_publish_a_retry_request(): void
    {
        $repository = new InMemoryActivationGrantRepository();
        $activationGrant = $this->grant();
        self::assertTrue($repository->add($activationGrant));
        $token = CredentialDeliveryClaimToken::generate();
        $at = new DateTimeImmutable('2026-08-18T12:00:00+00:00');
        $claimed = $activationGrant->claimDelivery($token, $at, $at->modify('+5 minutes'));
        self::assertTrue($repository->replace($activationGrant, $claimed));
        $activationGrant = $claimed->confirmDelivery($token, $at);
        self::assertTrue($repository->replace($claimed, $activationGrant));
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_missing_delivery_work_does_not_publish_a_retry_request(): void
    {
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            new InMemoryActivationGrantRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                UserId::generate()
            )));
        } finally {
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_that_an_audit_write_failure_prevents_retry_request_publication(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $activationGrant = $this->persistedFailedGrant($repository);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $auditEvidenceRepository,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );

        $this->expectException(RuntimeException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertEquals($activationGrant, $repository->getLatestByUserId($activationGrant->getUserId()));
            self::assertCount(0, $auditEvidenceRepository->all());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
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
        $repository = new InMemoryActivationGrantRepository(
            beforeReplace: static function (
                InMemoryActivationGrantRepository $repository,
                ActivationGrant $predecessor
            ) use ($successor): void {
                self::assertTrue($repository->replaceWithSuccessor(
                    $predecessor,
                    $predecessor->revoke(new DateTimeImmutable('2026-08-19T12:00:00+00:00')),
                    $successor
                ));
            },
            beforeReplaceOnCall: 3
        );
        $activationGrant = $this->persistedFailedGrant($repository, $userId);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository();
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $auditEvidenceRepository,
            new InMemoryUnitOfWork(),
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );

        $this->expectException(ActivationDeliveryNotRetryableException::class);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                'Admin-42',
                $activationGrant->getUserId()
            )));
        } finally {
            self::assertSame([], $auditEvidenceRepository->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            self::assertSame($successor, $repository->getLatestByUserId($activationGrant->getUserId()));
        }
    }

    public function test_boundary_admission_with_and_without_the_real_subscriber(): void
    {
        foreach ([false, true] as $subscribed) {
            foreach ([-1, 0, 1] as $offset) {
                $unitOfWork = new InMemoryUnitOfWork();
                $repository = new InMemoryActivationGrantRepository($unitOfWork);
                $grant = $this->persistedFailedGrant($repository);
                $at = $grant->getExpiresAt()->modify(sprintf('%+d seconds', $offset));
                $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
                $events = new SimpleEventDispatcher();
                $facts = [];
                $events->addHandler(
                    ClassName::underscore(InvitationDeliveryRetryRequested::class),
                    static function () use (&$facts, $unitOfWork, $audit): void {
                        self::assertFalse($unitOfWork->transactionActive);
                        self::assertCount(1, $audit->all());
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
                    $repository,
                    $grant
                ): void {
                    self::assertFalse($unitOfWork->transactionActive);
                    self::assertSame(2, $unitOfWork->transactions);
                    self::assertSame($grant->getDelivery()->getId()->toString(), $invocation->getIdempotencyId());
                    self::assertSame(
                        CredentialDeliveryStatus::CLAIMED,
                        $repository->getLatestByUserId($grant->getUserId())?->getDelivery()->getStatus()
                    );
                });
                if ($subscribed) {
                    $worker = new DeliverUserInvitationHandler(
                        $repository,
                        $audit,
                        $unitOfWork,
                        $cipher,
                        $provider,
                        new FixedClock($at),
                        $events
                    );
                    $bus = $this->createMock(CommandBus::class);
                    $bus->expects($offset < 0 ? self::once() : self::never())->method('execute')
                        ->willReturnCallback(static function (DeliverUserInvitation $command) use ($worker): void {
                            $worker->handle(CommandMessage::create($command));
                        });
                    $events->register(new InvitationDeliverySubscriber($bus));
                }

                $handler = new RetryInvitationDeliveryHandler(
                    $repository,
                    $audit,
                    $unitOfWork,
                    new FixedClock($at),
                    $events
                );
                $failure = null;
                try {
                    $handler->handle(CommandMessage::create(new RetryInvitationDelivery(
                        'Admin-42',
                        $grant->getUserId()
                    )));
                } catch (Throwable $throwable) {
                    $failure = $throwable;
                }

                $stored = $repository->getLatestByUserId($grant->getUserId());
                if ($offset >= 0) {
                    self::assertInstanceOf(ActivationDeliveryNotRetryableException::class, $failure);
                    self::assertSame('The activation delivery work is no longer retryable.', $failure->getMessage());
                    self::assertEquals($grant, $stored);
                    self::assertSame([], $audit->all());
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
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->persistedFailedGrant(
                $repository,
                expiresAt: new DateTimeImmutable('2026-08-25T12:00:00.123456Z')
            );
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $at = new DateTimeImmutable($time);
            $handler = new RetryInvitationDeliveryHandler(
                $repository,
                $audit,
                $unitOfWork,
                new FixedClock($at),
                $events
            );
            try {
                $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
                self::assertLessThan($grant->getExpiresAt(), $at);
                self::assertCount(1, $audit->all());
            } catch (ActivationDeliveryNotRetryableException $exception) {
                self::assertEquals($grant->getExpiresAt(), $at);
                self::assertSame('The activation delivery work is no longer retryable.', $exception->getMessage());
                self::assertEquals($grant, $repository->getLatestByUserId($grant->getUserId()));
                self::assertSame([], $audit->all());
            }
        }
    }

    public function test_clock_is_sampled_after_the_transactional_read_even_if_the_read_crosses_expiry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $storage = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($storage);
        $time = $grant->getExpiresAt()->modify('-1 second');
        $readCompleted = false;
        $repository = $this->createMock(ActivationGrantRepository::class);
        $repository->expects(self::once())->method('getLatestByUserId')->with($grant->getUserId())
            ->willReturnCallback(
                static function () use (&$time, &$readCompleted, $grant, $unitOfWork): ActivationGrant {
                    self::assertTrue($unitOfWork->transactionActive);
                    $time = $grant->getExpiresAt();
                    $readCompleted = true;

                    return $grant;
                }
            );
        $repository->expects(self::never())->method('replace');
        $clock = $this->createMock(Clock::class);
        $clock->expects(self::once())->method('now')->willReturnCallback(
            static function () use (&$time, &$readCompleted, $unitOfWork): DateTimeImmutable {
                self::assertTrue($readCompleted);
                self::assertTrue($unitOfWork->transactionActive);

                return $time;
            }
        );
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $audit,
            $unitOfWork,
            $clock,
            new InMemoryEventDispatcher()
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
            self::fail('A read crossing expiry must not reuse pre-transaction time.');
        } catch (ActivationDeliveryNotRetryableException) {
            self::assertSame([], $audit->all());
            self::assertEquals($grant, $storage->getLatestByUserId($grant->getUserId()));
        }
    }

    public function test_success_preserves_authority_identity_and_delivery_history(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            new InMemoryAuditEvidenceRepository($unitOfWork),
            $unitOfWork,
            new FixedClock('2026-08-25T11:59:59Z'),
            new InMemoryEventDispatcher()
        );
        $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));

        $stored = $repository->getLatestByUserId($grant->getUserId());
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
        self::assertCount(1, $repository->all());
    }

    public function test_non_retry_pending_and_terminal_authority_reject_without_changes(): void
    {
        foreach (['pending', 'claimed', 'delivered', 'permanent', 'consumed', 'revoked', 'expired'] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant();
            self::assertTrue($repository->add($grant));
            $at = new DateTimeImmutable('2026-08-18T12:00:00Z');
            $token = CredentialDeliveryClaimToken::generate();
            if (in_array($state, ['claimed', 'delivered', 'permanent'], true)) {
                $claimed = $grant->claimDelivery($token, $at, $at->modify('+5 minutes'));
                self::assertTrue($repository->replace($grant, $claimed));
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
                self::assertTrue($repository->replace($grant, $next));
            }

            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $handler = new RetryInvitationDeliveryHandler(
                $repository,
                $audit,
                $unitOfWork,
                new FixedClock('2026-08-19T12:00:00Z'),
                $events
            );
            try {
                $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
                self::fail('Ineligible work accepted: '.$state);
            } catch (ActivationDeliveryNotRetryableException $exception) {
                self::assertSame('The activation delivery work is no longer retryable.', $exception->getMessage());
                self::assertEquals($next, $repository->getLatestByUserId($grant->getUserId()));
                self::assertSame([], $audit->all());
                self::assertCount(1, $events->events());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    public function test_commit_failure_rolls_back_both_persisted_retry_and_audit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork(failOnTransaction: 1);
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $audit,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
            self::fail('Commit failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame('Injected transaction failure.', $runtimeException->getMessage());
            self::assertEquals($grant, $repository->getLatestByUserId($grant->getUserId()));
            self::assertSame([], $audit->all());
            self::assertCount(1, $events->events());
            self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
        }
    }

    public function test_failure_notification_cannot_replace_the_expected_expiry_rejection(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $command = new RetryInvitationDelivery('Admin-42', $grant->getUserId());
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $notified = null;
        $events = new InMemoryEventDispatcher(static function (CommandFailedEvent $event) use (&$notified): void {
            $notified = $event;

            throw new RuntimeException('Secondary notification failure.');
        });
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $audit,
            $unitOfWork,
            new FixedClock($grant->getExpiresAt()),
            $events
        );
        try {
            $handler->handle(CommandMessage::create($command));
            self::fail('Expiry was accepted.');
        } catch (ActivationDeliveryNotRetryableException $activationDeliveryNotRetryableException) {
            self::assertSame(
                'The activation delivery work is no longer retryable.',
                $activationDeliveryNotRetryableException->getMessage()
            );
            self::assertInstanceOf(CommandFailedEvent::class, $notified);
            self::assertSame($command, $notified->getCommand());
            self::assertSame('Invitation delivery retry failed.', $notified->getErrorMessage());
            self::assertEquals($grant, $repository->getLatestByUserId($grant->getUserId()));
            self::assertSame([], $audit->all());
        }
    }

    public function test_publication_failure_keeps_committed_retry_and_original_throwable(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $primary = new RuntimeException('Publication failed.');
        $events = new InMemoryEventDispatcher(static function (Event $event) use ($primary, $unitOfWork): void {
            self::assertFalse($unitOfWork->transactionActive);
            if ($event instanceof InvitationDeliveryRetryRequested) {
                throw $primary;
            }

            throw new RuntimeException('Secondary notification failure.');
        });
        $handler = new RetryInvitationDeliveryHandler(
            $repository,
            $audit,
            $unitOfWork,
            new FixedClock('2026-08-19T12:00:00Z'),
            $events
        );
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
            self::fail('Publication failure was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($primary, $runtimeException);
            self::assertEquals($grant->requestDeliveryRetry(), $repository->getLatestByUserId($grant->getUserId()));
            self::assertCount(1, $audit->all());
        }
    }

    public function test_competing_commits_win_without_the_losing_retry_undoing_them(): void
    {
        foreach (['resend', 'consume', 'revoke', 'expiry', 'claim', 'retry'] as $winner) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository(
                $unitOfWork,
                beforeReplace: static function () use ($unitOfWork): void {
                    $unitOfWork->suspendBeforeFirstWrite();
                },
                beforeReplaceOnCall: 3
            );
            $grant = $this->persistedFailedGrant($repository);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $loserEvents = new InMemoryEventDispatcher();
            $handler = new RetryInvitationDeliveryHandler(
                $repository,
                $audit,
                $unitOfWork,
                new FixedClock('2026-08-19T12:00:00Z'),
                $loserEvents
            );
            $loser = new Fiber(static function () use ($handler, $grant): void {
                $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Loser', $grant->getUserId())));
            });
            $loser->start();
            self::assertTrue($loser->isSuspended());
            if ($winner === 'retry') {
                new RetryInvitationDeliveryHandler(
                    $repository,
                    $audit,
                    $unitOfWork,
                    new FixedClock('2026-08-19T12:00:00Z'),
                    new InMemoryEventDispatcher()
                )->handle(CommandMessage::create(new RetryInvitationDelivery('Winner', $grant->getUserId())));
            } else {
                $unitOfWork->commitTransactional(static function () use ($repository, $grant, $winner, $audit): void {
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
                        self::assertTrue($repository->replaceWithSuccessor($grant, $grant->revoke($at), $successor));
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
                        self::assertTrue($repository->replace($grant, $replacement));
                    }

                    $audit->add(AuditEvidence::record('Winner', 'winner.'.$winner, $grant->getUserId()));
                });
            }

            $committedWinner = $repository->getLatestByUserId($grant->getUserId());
            $winnerAudit = $audit->all();
            try {
                $loser->resume();
                self::fail('Stale retry overwrote '.$winner);
            } catch (ActivationDeliveryNotRetryableException $exception) {
                self::assertSame('The activation delivery generation changed concurrently.', $exception->getMessage());
                self::assertSame($committedWinner, $repository->getLatestByUserId($grant->getUserId()));
                self::assertSame($winnerAudit, $audit->all());
                self::assertCount(1, $winnerAudit);
                self::assertCount(1, $loserEvents->events());
                self::assertInstanceOf(CommandFailedEvent::class, $loserEvents->events()[0]);
                self::assertFalse($repository->replace($grant, $grant->requestDeliveryRetry()));
            }
        }
    }

    public function test_retry_first_keeps_later_lifecycle_and_claim_successors_authoritative(): void
    {
        foreach (['consume', 'revoke', 'expiry', 'claim', 'resend'] as $next) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->persistedFailedGrant($repository);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $handler = new RetryInvitationDeliveryHandler(
                $repository,
                $audit,
                $unitOfWork,
                new FixedClock('2026-08-19T12:00:00Z'),
                new InMemoryEventDispatcher()
            );
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
            $current = $repository->getLatestByUserId($grant->getUserId());
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
                self::assertTrue($repository->replaceWithSuccessor($current, $current->revoke($at), $successor));
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
                self::assertTrue($repository->replace($current, $successor));
            }

            self::assertFalse($repository->replace($grant, $grant->requestDeliveryRetry()));
            self::assertSame($successor, $repository->getLatestByUserId($grant->getUserId()));
            self::assertCount(1, $audit->all());
        }
    }

    public function test_expiry_after_admission_rejects_subscriber_claim_but_keeps_committed_retry(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new SimpleEventDispatcher();
        $clock = new FixedClock($grant->getExpiresAt()->modify('-1 second'), $grant->getExpiresAt());
        $cipher = $this->createMock(InvitationDeliveryCipher::class);
        $cipher->expects(self::never())->method('decrypt');
        $provider = new RecordingCredentialDeliveryProvider();
        $worker = new DeliverUserInvitationHandler(
            $repository,
            $audit,
            $unitOfWork,
            $cipher,
            $provider,
            $clock,
            $events
        );
        $bus = $this->createMock(CommandBus::class);
        $bus->expects(self::once())->method('execute')->willReturnCallback(
            static function (DeliverUserInvitation $command) use ($worker): void {
                $worker->handle(CommandMessage::create($command));
            }
        );
        $events->register(new InvitationDeliverySubscriber($bus));
        $handler = new RetryInvitationDeliveryHandler($repository, $audit, $unitOfWork, $clock, $events);
        try {
            $handler->handle(CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId())));
            self::fail('The expired subscriber claim was accepted.');
        } catch (EventDispatchFailed) {
            self::assertSame(2, $clock->calls());
            self::assertEquals($grant->requestDeliveryRetry(), $repository->getLatestByUserId($grant->getUserId()));
            self::assertCount(1, $audit->all());
            self::assertSame('user.invitation_delivery.retry_requested', $audit->all()[0]->action());
            self::assertSame([], $provider->invocations());
        }
    }

    public function test_expired_retry_requires_explicit_resend_with_fresh_authority(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $repository = new InMemoryActivationGrantRepository($unitOfWork);
        $grant = $this->persistedFailedGrant($repository);
        $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $clock = new FixedClock($grant->getExpiresAt());
        $resend = new ResendInvitationDeliveryHandler(
            $repository,
            $audit,
            $unitOfWork,
            new FixedCredentialGenerator('fresh-authority'),
            new PrefixInvitationDeliveryCipher(),
            $clock,
            $events
        );
        $resend->handle(CommandMessage::create(new ResendInvitationDelivery('Admin-42', $grant->getUserId())));

        $successor = $repository->getLatestByUserId($grant->getUserId());
        self::assertNotNull($successor);
        self::assertFalse($grant->getId()->equals($successor->getId()));
        self::assertFalse($grant->getDelivery()->getId()->equals($successor->getDelivery()->getId()));
        self::assertNotSame($grant->getCredentialHash(), $successor->getCredentialHash());
        self::assertGreaterThan($grant->getExpiresAt(), $successor->getExpiresAt());
        self::assertTrue($repository->getById($grant->getId())?->isRevoked());
        self::assertFalse($repository->replace($grant, $grant->requestDeliveryRetry()));
        self::assertSame($successor, $repository->getLatestByUserId($grant->getUserId()));

        $token = CredentialDeliveryClaimToken::generate();
        $at = $grant->getExpiresAt();
        $claimed = $successor->claimDelivery($token, $at, $at->modify('+5 minutes'));
        self::assertTrue($repository->replace($successor, $claimed));
        $failed = $claimed->failDelivery($token, $at, CredentialDeliveryFailure::RETRYABLE_PROVIDER);
        self::assertTrue($repository->replace($claimed, $failed));
        new RetryInvitationDeliveryHandler($repository, $audit, $unitOfWork, $clock, $events)->handle(
            CommandMessage::create(new RetryInvitationDelivery('Admin-42', $grant->getUserId()))
        );
        self::assertEquals($failed->requestDeliveryRetry(), $repository->getLatestByUserId($grant->getUserId()));
        self::assertTrue($repository->getById($grant->getId())->isRevoked());
        self::assertCount(2, $audit->all());
    }

    public function test_that_command_and_event_round_trip_and_reject_missing_data(): void
    {
        $command = new RetryInvitationDelivery('Admin-42', UserId::generate());
        $event = new InvitationDeliveryRetryRequested(
            'Admin-42',
            $command->getUserId(),
            ActivationDeliveryId::generate()
        );

        self::assertEquals($command, RetryInvitationDelivery::fromArray($command->toArray()));
        self::assertEquals($event, InvitationDeliveryRetryRequested::fromArray($event->toArray()));
        self::assertSame('Admin-42', $event->getActorId());
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
        InMemoryActivationGrantRepository $repository,
        ?UserId $userId = null,
        ?DateTimeImmutable $expiresAt = null
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
        self::assertTrue($repository->add($activationGrant));
        $token = CredentialDeliveryClaimToken::generate();
        $at = new DateTimeImmutable('2026-08-18T12:00:00+00:00');
        $claimed = $activationGrant->claimDelivery($token, $at, $at->modify('+5 minutes'));
        self::assertTrue($repository->replace($activationGrant, $claimed));
        $failed = $claimed->failDelivery($token, $at, CredentialDeliveryFailure::UNEXPECTED_PROVIDER);
        self::assertTrue($repository->replace($claimed, $failed));

        return $failed;
    }
}
