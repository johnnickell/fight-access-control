<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\RetryInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryInvocation;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Application\AccessControl\User\CommandHandler\CorrectPendingInvitationHandler;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception\ActivationDeliveryNotRetryableException;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\Query\CredentialDeliveryStatusView;
use Fight\AccessControl\Domain\AccessControl\User\Command\CorrectPendingInvitation;
use Fight\AccessControl\Domain\AccessControl\User\Exception\PendingInvitationCorrectionException;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Value\Internet\EmailAddress;
use Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service as ActivationService;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ActivationGrant::class)]
#[CoversClass(DeliverUserInvitationHandler::class)]
#[CoversClass(ResendInvitationDeliveryHandler::class)]
#[CoversClass(RetryInvitationDeliveryHandler::class)]
#[CoversClass(CredentialDeliveryInvocation::class)]
#[CoversClass(CorrectPendingInvitationHandler::class)]
final class ActivationOriginRecoveryTest extends TestCase
{
    public function test_immediate_dispatch_uses_stored_origin_and_does_not_activate_the_user(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            $fixture = new ActivationOriginFixture($origin);
            $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function (
                CredentialDeliveryInvocation $invocation
            ) use (
                $fixture,
                $origin
): void {
                self::assertFalse($fixture->unitOfWork->transactionActive);
                self::assertSame($origin, $invocation->getActivationOrigin());
                self::assertSame('activation', $invocation->getPurpose());
                self::assertSame('origin@example.test', $invocation->getEmail()->canonical());
                self::assertSame('origin-fixture', $invocation->getCredential());
                self::assertSame(
                    $fixture->initial->getDelivery()->getId()->toString(),
                    $invocation->getIdempotencyId()
                );
                self::assertSame(
                    CredentialDeliveryStatus::CLAIMED,
                    $fixture->grants->all()[0]->getDelivery()->getStatus()
                );
            });
            $fixture->worker($provider)->handle(CommandMessage::create($this->command($fixture->initial)));
            self::assertCount(1, $provider->invocations());
            self::assertSame($origin, $fixture->grants->all()[0]->getOrigin());
            self::assertTrue($fixture->grants->all()[0]->isIssued());
            self::assertSame(
                UserState::PENDING_ACTIVATION,
                $fixture->users->getById($fixture->user->getId())?->getState()
            );
            self::assertSame(
                CredentialDeliveryStatus::DELIVERED,
                $fixture->grants->all()[0]->getDelivery()->getStatus()
            );
        }
    }

    public function test_scheduler_restart_retry_and_manual_retry_retain_both_origins_and_identity(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            foreach ([false, true] as $manualRetry) {
                $fixture = new ActivationOriginFixture($origin);
                $provider = new RecordingCredentialDeliveryProvider([
                    new RuntimeException('private vendor diagnostic')
                ]);
                $fixture->worker($provider)->handle(CommandMessage::create($this->command($fixture->initial)));
                $failed = $fixture->grants->all()[0];
                self::assertSame(CredentialDeliveryStatus::RETRY_PENDING, $failed->getDelivery()->getStatus());
                self::assertSame([], $fixture->discover('2030-01-01T00:00:59Z'));
                if ($manualRetry) {
                    new RetryInvitationDeliveryHandler(
                        $fixture->grants,
                        $fixture->audit,
                        $fixture->unitOfWork,
                        new FixedClock('2030-01-01T00:00:01Z'),
                        new InMemoryEventDispatcher()
                    )->handle(CommandMessage::create(new RetryInvitationDelivery('worker', $fixture->user->getId())));
                }

                $fixture->grants = $fixture->grants->restart();
                $due = $fixture->discover('2030-01-01T00:01:00Z');
                self::assertCount(1, $due);
                self::assertSame('activation', $due[0]->getPurpose());
                $fixture->worker($provider, '2030-01-01T00:01:00Z')->handle(CommandMessage::create(
                    DeliverUserInvitation::fromArray([
                        'actor_id'               => 'restarted-worker',
                        'user_id'                => $due[0]->getUserId()->toString(),
                        'activation_delivery_id' => $due[0]->getDeliveryId()->toString()
                    ])
                ));
                $stored = $fixture->grants->all()[0];
                self::assertSame($origin, $stored->getOrigin());
                self::assertSame(2, $stored->getDelivery()->getAttemptCount());
                self::assertSame(CredentialDeliveryStatus::DELIVERED, $stored->getDelivery()->getStatus());
                foreach ($provider->invocations() as $invocation) {
                    self::assertSame($origin, $invocation->getActivationOrigin());
                    self::assertSame(
                        $fixture->initial->getDelivery()->getId()->toString(),
                        $invocation->getIdempotencyId()
                    );
                }

                $safe = json_encode(CredentialDeliveryStatusView::fromDelivery(
                    'activation',
                    $stored->getDelivery(),
                    $stored->getRevision()
                )->toArray(), JSON_THROW_ON_ERROR);
                foreach (['origin-fixture', 'ciphertext:', 'private vendor diagnostic'] as $secret) {
                    self::assertStringNotContainsString($secret, $safe);
                }
            }
        }
    }

    public function test_failed_claim_commit_never_invokes_but_lost_outcome_recovers_the_same_origin_after_lease(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            foreach ([1, 2] as $failedTransaction) {
                $fixture = new ActivationOriginFixture($origin, new InMemoryUnitOfWork($failedTransaction));
                $provider = new RecordingCredentialDeliveryProvider();
                $events = new InMemoryEventDispatcher();
                try {
                    $fixture->worker($provider, events: $events)->handle(
                        CommandMessage::create($this->command($fixture->initial))
                    );
                    self::fail('The injected commit failure was accepted.');
                } catch (RuntimeException $exception) {
                    self::assertSame('Injected transaction failure.', $exception->getMessage());
                }

                self::assertCount($failedTransaction - 1, $provider->invocations());
                self::assertSame($origin, $fixture->grants->all()[0]->getOrigin());
                self::assertSame([], $fixture->audit->all());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
                self::assertSame(
                    $this->command($fixture->initial)->toArray(),
                    $events->events()[0]->getCommand()->toArray()
                );
                $fixture->grants = $fixture->grants->restart();
                if ($failedTransaction === 2) {
                    self::assertSame([], $fixture->discover('2030-01-01T00:04:59Z'));
                }

                $due = $fixture->discover('2030-01-01T00:05:00Z');
                self::assertCount(1, $due);
                $fixture->worker($provider, '2030-01-01T00:05:00Z')->handle(
                    CommandMessage::create($this->command($fixture->initial))
                );
                self::assertSame(
                    CredentialDeliveryStatus::DELIVERED,
                    $fixture->grants->all()[0]->getDelivery()->getStatus()
                );
                foreach ($provider->invocations() as $invocation) {
                    self::assertSame($origin, $invocation->getActivationOrigin());
                    self::assertSame(
                        $fixture->initial->getDelivery()->getId()->toString(),
                        $invocation->getIdempotencyId()
                    );
                }
            }
        }
    }

    public function test_uncertain_claim_and_outcome_commits_recover_from_authoritative_state(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            foreach ([1, 2] as $uncertainAt) {
                foreach ([false, true] as $committed) {
                    $fixture = new ActivationOriginFixture($origin);
                    $provider = new RecordingCredentialDeliveryProvider();
                    $uncertain = new ActivationService\UncertainActivationUnitOfWork(
                        $fixture->unitOfWork,
                        $uncertainAt,
                        $committed
                    );
                    try {
                        $fixture->worker($provider, unitOfWork: $uncertain)->handle(
                            CommandMessage::create($this->command($fixture->initial))
                        );
                        self::fail('An uncertain result was reported as success.');
                    } catch (RuntimeException $exception) {
                        self::assertSame('Uncertain transaction result.', $exception->getMessage());
                    }

                    $fixture->grants = $fixture->grants->restart();
                    $stored = $fixture->grants->all()[0];
                    self::assertSame($origin, $stored->getOrigin());
                    self::assertCount($uncertainAt - 1, $provider->invocations());
                    $due = $fixture->discover('2030-01-01T00:05:00Z');
                    if ($uncertainAt === 2 && $committed) {
                        self::assertSame(CredentialDeliveryStatus::DELIVERED, $stored->getDelivery()->getStatus());
                        self::assertSame([], $due);
                        self::assertCount(1, $fixture->audit->all());
                        continue;
                    }

                    self::assertCount(1, $due);
                    self::assertSame([], $fixture->audit->all());
                    $fixture->worker($provider, '2030-01-01T00:05:00Z')->handle(
                        CommandMessage::create($this->command($fixture->initial))
                    );
                    self::assertSame(
                        CredentialDeliveryStatus::DELIVERED,
                        $fixture->grants->all()[0]->getDelivery()->getStatus()
                    );
                    foreach ($provider->invocations() as $invocation) {
                        self::assertSame($origin, $invocation->getActivationOrigin());
                        self::assertSame(
                            $fixture->initial->getDelivery()->getId()->toString(),
                            $invocation->getIdempotencyId()
                        );
                    }
                }
            }
        }
    }

    public function test_resend_rolls_back_on_commit_failure_then_recovers_after_committed_publication_failure(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            $fixture = new ActivationOriginFixture($origin);
            $command = CommandMessage::create(new ResendInvitationDelivery('worker', $fixture->user->getId()));
            $fixture->unitOfWork->failNextCommit = true;
            try {
                $fixture->resend()->handle($command);
                self::fail('A failed replacement commit was accepted.');
            } catch (RuntimeException) {
            }

            self::assertCount(1, $fixture->grants->all());
            self::assertEquals($fixture->initial, $fixture->grants->all()[0]);
            self::assertSame([], $fixture->audit->all());
            $fault = new RuntimeException('Publication failed.');
            $events = new InMemoryEventDispatcher(static function ($event) use ($fault): void {
                if (!$event instanceof CommandFailedEvent) {
                    throw $fault;
                }
            });
            try {
                $fixture->resend($events)->handle($command);
                self::fail('Publication failure was hidden.');
            } catch (RuntimeException $exception) {
                self::assertSame($fault, $exception);
            }

            self::assertCount(2, $fixture->grants->all());
            self::assertTrue($fixture->grants->all()[0]->isRevoked());
            self::assertSame($origin, $fixture->grants->all()[0]->getOrigin());
            self::assertNull($fixture->grants->all()[0]->getDelivery()->getEncryptedMaterial());
            self::assertCount(1, $fixture->audit->all());
            $fixture->grants = $fixture->grants->restart();
            $successor = $fixture->grants->all()[1];
            self::assertSame($origin, $successor->getOrigin());
            self::assertFalse($successor->getDelivery()->getId()->equals($fixture->initial->getDelivery()->getId()));
            self::assertFalse($successor->matchesCredential(ActivationCredential::fromString('origin-fixture')));
            $provider = new RecordingCredentialDeliveryProvider();
            try {
                $fixture->worker($provider, '2030-01-01T00:01:00Z')->handle(
                    CommandMessage::create($this->command($fixture->initial))
                );
                self::fail('Obsolete work invoked the provider.');
            } catch (ActivationDeliveryNotRetryableException) {
                self::assertCount(0, $provider->invocations());
            }

            $due = $fixture->discover('2030-01-01T00:01:00Z');
            self::assertCount(1, $due);
            self::assertSame($successor->getDelivery()->getId()->toString(), $due[0]->getDeliveryId()->toString());
            $fixture->worker($provider, '2030-01-01T00:01:00Z')->handle(
                CommandMessage::create($this->command($successor))
            );
            self::assertCount(1, $provider->invocations());
            self::assertSame($origin, $provider->invocations()[0]->getActivationOrigin());
            self::assertSame('successor-fixture', $provider->invocations()[0]->getCredential());
        }
    }

    public function test_replacement_during_admitted_provider_invocation_fences_the_old_outcome(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            $fixture = new ActivationOriginFixture($origin);
            $provider = new RecordingCredentialDeliveryProvider(onDeliver: static function () use ($fixture): void {
                $fixture->resend()->handle(CommandMessage::create(new ResendInvitationDelivery(
                    'worker',
                    $fixture->user->getId()
                )));
            });
            try {
                $fixture->worker($provider)->handle(CommandMessage::create($this->command($fixture->initial)));
                self::fail('An obsolete provider outcome overwrote the successor.');
            } catch (ActivationDeliveryNotRetryableException) {
                self::assertCount(1, $provider->invocations());
            }

            $successor = $fixture->grants->all()[1];
            self::assertSame($origin, $successor->getOrigin());
            self::assertSame(0, $successor->getRevision());
            self::assertSame(CredentialDeliveryStatus::PENDING, $successor->getDelivery()->getStatus());
            $freshProvider = new RecordingCredentialDeliveryProvider();
            $fixture->worker($freshProvider, '2030-01-01T00:01:00Z')->handle(
                CommandMessage::create($this->command($successor))
            );
            self::assertSame($origin, $freshProvider->invocations()[0]->getActivationOrigin());
        }
    }

    public function test_terminal_outcome_keeps_origin_despite_post_commit_publication_failure(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            foreach ([CredentialDeliveryOutcome::PERMANENT_FAILURE, CredentialDeliveryOutcome::DELIVERED] as $outcome) {
                $fixture = new ActivationOriginFixture($origin);
                $fault = new RuntimeException('Publication failed.');
                $events = new InMemoryEventDispatcher(static function ($event) use ($fault): void {
                    if (!$event instanceof CommandFailedEvent) {
                        throw $fault;
                    }
                });
                $provider = new RecordingCredentialDeliveryProvider([$outcome]);
                try {
                    $fixture->worker($provider, events: $events)->handle(
                        CommandMessage::create($this->command($fixture->initial))
                    );
                    self::assertSame(CredentialDeliveryOutcome::PERMANENT_FAILURE, $outcome);
                } catch (RuntimeException $exception) {
                    self::assertSame($fault, $exception);
                    self::assertSame(CredentialDeliveryOutcome::DELIVERED, $outcome);
                }

                $fixture->grants = $fixture->grants->restart();
                $stored = $fixture->grants->all()[0];
                self::assertSame($origin, $stored->getOrigin());
                self::assertSame($origin, $provider->invocations()[0]->getActivationOrigin());
                self::assertTrue($stored->isIssued());
                self::assertNull($stored->getDelivery()->getEncryptedMaterial());
                self::assertSame(2, $stored->getRevision());
                self::assertCount(1, $fixture->audit->all());
                self::assertSame([], $fixture->discover('2030-01-01T00:01:00Z'));
            }
        }
    }

    public function test_private_correction_stays_private_and_registration_correction_rejects_before_any_write(): void
    {
        foreach (ActivationOrigin::cases() as $origin) {
            $fixture = new ActivationOriginFixture($origin);
            $events = new InMemoryEventDispatcher();
            $handler = new CorrectPendingInvitationHandler(
                $fixture->users,
                $fixture->grants,
                new ActivationService\FixedInvitationAdministrationAuthorization(true),
                $fixture->audit,
                $fixture->unitOfWork,
                new ActivationService\FixedCredentialGenerator('corrected-fixture'),
                new ActivationService\PrefixInvitationDeliveryCipher(),
                new FixedClock('2030-01-01T00:01:00Z'),
                $events
            );
            try {
                $handler->handle(CommandMessage::create(new CorrectPendingInvitation(
                    UserId::generate(),
                    $fixture->user->getId(),
                    EmailAddress::fromString('corrected@example.test')
                )));
                self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $origin);
                $successor = $fixture->grants->all()[1];
                self::assertSame(ActivationOrigin::PRIVATE_INVITATION, $successor->getOrigin());
                self::assertTrue($fixture->grants->all()[0]->isRevoked());
                $provider = new RecordingCredentialDeliveryProvider();
                $fixture->worker($provider, '2030-01-01T00:01:00Z')->handle(
                    CommandMessage::create($this->command($successor))
                );
                self::assertSame('corrected@example.test', $provider->invocations()[0]->getEmail()->canonical());
                self::assertSame(
                    ActivationOrigin::PRIVATE_INVITATION,
                    $provider->invocations()[0]->getActivationOrigin()
                );
            } catch (PendingInvitationCorrectionException) {
                self::assertSame(ActivationOrigin::REGISTRATION, $origin);
                self::assertSame(
                    'origin@example.test',
                    $fixture->users->getById($fixture->user->getId())?->getEmail()->canonical()
                );
                self::assertCount(1, $fixture->grants->all());
                self::assertEquals($fixture->initial, $fixture->grants->all()[0]);
                self::assertSame([], $fixture->audit->all());
                self::assertCount(1, $events->events());
                self::assertInstanceOf(CommandFailedEvent::class, $events->events()[0]);
            }
        }
    }

    private function command(ActivationGrant $grant): DeliverUserInvitation
    {
        return new DeliverUserInvitation('worker', $grant->getUserId(), $grant->getDelivery()->getId());
    }
}
