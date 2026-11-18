<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\DeliverUserInvitationHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ExpireInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\ResendInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\ActivationGrant\CommandHandler\RetryInvitationDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service\CredentialDeliveryOutcome;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrant;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationGrantRepository;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationOrigin;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\DeliverUserInvitation;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ExpireInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\ResendInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command\RetryInvitationDelivery;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event\InvitationDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryClaimToken;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Common\Domain\Messaging\Event\Event;
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

#[CoversClass(DeliverUserInvitationHandler::class)]
#[CoversClass(ResendInvitationDeliveryHandler::class)]
#[CoversClass(RetryInvitationDeliveryHandler::class)]
#[CoversClass(ExpireInvitationDeliveryHandler::class)]
#[CoversClass(ExpireInvitationDelivery::class)]
#[CoversClass(InvitationDeliveryExpired::class)]
#[CoversClass(AuditEvidence::class)]
final class InvitationAuditTest extends TestCase
{
    public function test_every_handler_accepts_omitted_and_explicit_null_storage(): void
    {
        foreach (['deliver', 'resend', 'retry', 'expire'] as $operation) {
            foreach ([false, true] as $explicitNull) {
                $unitOfWork = new InMemoryUnitOfWork();
                $repository = new InMemoryActivationGrantRepository($unitOfWork);
                $grant = $this->grant($repository, $operation);
                $events = new InMemoryEventDispatcher();
                $provider = new RecordingCredentialDeliveryProvider();
                $optional = $explicitNull ? [null] : [];
                $clock = new FixedClock('2030-01-01T01:00:00Z');
                $handler = match ($operation) {
                    'deliver' => new DeliverUserInvitationHandler(
                        $repository,
                        $unitOfWork,
                        new PrefixInvitationDeliveryCipher(),
                        $provider,
                        $clock,
                        $events,
                        ...$optional
                    ),
                    'resend' => new ResendInvitationDeliveryHandler(
                        $repository,
                        $unitOfWork,
                        new FixedCredentialGenerator('replacement'),
                        new PrefixInvitationDeliveryCipher(),
                        $clock,
                        $events,
                        ...$optional
                    ),
                    'retry' => new RetryInvitationDeliveryHandler(
                        $repository,
                        $unitOfWork,
                        $clock,
                        $events,
                        ...$optional
                    ),
                    'expire' => new ExpireInvitationDeliveryHandler($repository, $unitOfWork, $events, ...$optional)
                };
                $handler->handle(CommandMessage::create($this->command($operation, $grant)));
                $stored = $repository->getLatestByUserId($grant->getUserId());
                self::assertNotNull($stored);
                self::assertSame(ActivationOrigin::REGISTRATION, $stored->getOrigin());
                self::assertCount(1, $events->events());
                self::assertSame($operation === 'deliver' ? 1 : 0, count($provider->invocations()));
                self::assertSame(match ($operation) {
                    'deliver' => CredentialDeliveryStatus::DELIVERED,
                    'expire' => CredentialDeliveryStatus::EXPIRED,
                    default => CredentialDeliveryStatus::PENDING
                }, $stored->getDelivery()->getStatus());
            }
        }
    }

    public function test_expiry_records_only_committed_changes_at_the_admitted_occurrence_time(): void
    {
        foreach ([ActivationOrigin::REGISTRATION, ActivationOrigin::PRIVATE_INVITATION] as $origin) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant($repository, origin: $origin);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $handler = new ExpireInvitationDeliveryHandler($repository, $unitOfWork, $events, $audit);
            $actor = AgentId::generate();
            $at = $grant->getExpiresAt()->modify('+1 microsecond');
            $command = new ExpireInvitationDelivery($actor, $grant->getUserId(), $grant->getDelivery()->getId(), $at);
            $message = CommandMessage::create($command);
            $handler->handle($message);
            $stored = $repository->restart()->getLatestByUserId($grant->getUserId());
            self::assertSame($origin, $stored->getOrigin());
            self::assertTrue($stored->isIssued());
            self::assertNull($stored->getDelivery()->getEncryptedMaterial());
            self::assertCount(1, $audit->all());
            $evidence = $audit->all()[0];
            self::assertSame(AuditAction::INVITATION_DELIVERY_EXPIRED, $evidence->action());
            self::assertSame($actor, $evidence->actorId());
            self::assertEquals($at, $evidence->getOccurredAt());
            self::assertEquals($message->id(), $evidence->getCorrelation()->getMessageId());
            self::assertEquals($grant->getUserId(), $evidence->getSubject()->getId());
            $facts = $evidence->getFacts();
            self::assertInstanceOf(AuditGrantOutcome::class, $facts);
            self::assertSame($grant->getRevision(), $facts->getBefore()->toArray()['revision']);
            self::assertSame($stored->getRevision(), $facts->getAfter()->toArray()['revision']);
            self::assertEquals($stored->getId(), $facts->getAfter()->getId());
            self::assertSame($grant->getDelivery()->getId()->toString(), $facts->getAfter()->toArray()['delivery_id']);
            self::assertSame(CredentialDeliveryStatus::EXPIRED, $facts->getDeliveryStatus());
            self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
            self::assertSame($command->toArray(), ExpireInvitationDelivery::fromArray($command->toArray())->toArray());
            $event = $events->events()[0];
            self::assertInstanceOf(InvitationDeliveryExpired::class, $event);
            self::assertSame($event->toArray(), InvitationDeliveryExpired::fromArray($event->toArray())->toArray());
            $handler->handle($message);
            self::assertCount(1, $audit->all());
            self::assertCount(1, $events->events());
        }
    }

    public function test_enabled_expiry_no_change_and_CAS_loss_never_write_evidence(): void
    {
        foreach (['early', 'unknown', 'owner', 'revoked', 'delivered', 'obsolete', 'cas'] as $state) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant($repository);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $events = new InMemoryEventDispatcher();
            $at = $grant->getExpiresAt();
            $userId = $grant->getUserId();
            $deliveryId = $grant->getDelivery()->getId();
            if ($state === 'early') {
                $at = $at->modify('-1 microsecond');
            } elseif ($state === 'unknown') {
                $deliveryId = ActivationDeliveryId::generate();
            } elseif ($state === 'owner') {
                $userId = UserId::generate();
            } elseif ($state === 'revoked') {
                self::assertTrue($repository->replace($grant, $grant->revoke($at->modify('-1 second'))));
            } elseif ($state === 'delivered') {
                $token = CredentialDeliveryClaimToken::generate();
                $claimedAt = $at->modify('-1 minute');
                $claimed = $grant->claimDelivery($token, $claimedAt, $at);
                self::assertTrue($repository->replace($grant, $claimed));
                self::assertTrue($repository->replace($claimed, $claimed->confirmDelivery($token, $claimedAt)));
            } elseif ($state === 'obsolete') {
                $replacement = ActivationGrant::issue(
                    $grant->getOrigin(),
                    $grant->getUserId(),
                    ActivationCredential::fromString('replacement'),
                    $at,
                    $at->modify('+1 day'),
                    $grant->getDelivery()->getEmail(),
                    'replacement-material'
                );
                self::assertTrue($repository->replaceWithSuccessor($grant, $grant->revoke($at), $replacement));
            }

            $before = $repository->all();
            $selectedRepository = $repository;
            if ($state === 'cas') {
                $selectedRepository = $this->createMock(ActivationGrantRepository::class);
                $selectedRepository->method('getByDeliveryId')->willReturn($grant);
                $selectedRepository->method('getLatestByUserId')->willReturn($grant);
                $selectedRepository->expects(self::once())->method('replace')->willReturn(false);
            }

            new ExpireInvitationDeliveryHandler($selectedRepository, $unitOfWork, $events, $audit)->handle(
                CommandMessage::create(new ExpireInvitationDelivery(SystemId::generate(), $userId, $deliveryId, $at))
            );
            self::assertSame($before, $repository->all());
            self::assertSame([], $audit->all());
            self::assertSame([], $events->events());
        }
    }

    public function test_expiry_audit_and_commit_faults_roll_back_and_preserve_primary_failure(): void
    {
        foreach (['audit', 'commit'] as $fault) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant($repository);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: $fault === 'audit');
            $events = new InMemoryEventDispatcher(static function (): void {
                throw new RuntimeException('Secondary notification failure.');
            });
            $unitOfWork->failNextCommit = $fault === 'commit';
            try {
                new ExpireInvitationDeliveryHandler($repository, $unitOfWork, $events, $audit)->handle(
                    CommandMessage::create($this->command('expire', $grant))
                );
                self::fail('Required persistence fault was hidden.');
            } catch (RuntimeException $exception) {
                if ($fault === 'audit') {
                    self::assertSame($audit->failure(), $exception);
                } else {
                    self::assertSame('Injected transaction failure.', $exception->getMessage());
                }

                self::assertSame($grant, $repository->getLatestByUserId($grant->getUserId()));
                $reopened = $repository->restart()->getLatestByUserId($grant->getUserId());
                self::assertSame($grant->getRevision(), $reopened->getRevision());
                self::assertTrue($grant->getDelivery()->sameStateAs($reopened->getDelivery()));
                self::assertSame([], $audit->all());
            }
        }
    }

    public function test_expiry_notification_and_lost_commit_acknowledgement_do_not_erase_evidence(): void
    {
        foreach (['notification', 'acknowledgement'] as $fault) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant($repository);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork);
            $primary = new RuntimeException('Primary fault.');
            if ($fault === 'acknowledgement') {
                $unitOfWork->afterCommit = static function () use ($primary): void {
                    throw $primary;
                };
            }

            $events = new InMemoryEventDispatcher(static function (Event $event) use ($primary): void {
                if ($event instanceof InvitationDeliveryExpired) {
                    throw $primary;
                }

                self::assertInstanceOf(CommandFailedEvent::class, $event);
                self::assertSame('Credential expiry failed.', $event->getErrorMessage());
                throw new RuntimeException('Secondary notification failure.');
            });
            try {
                new ExpireInvitationDeliveryHandler($repository, $unitOfWork, $events, $audit)->handle(
                    CommandMessage::create($this->command('expire', $grant))
                );
                self::fail('Primary fault was hidden.');
            } catch (RuntimeException $exception) {
                self::assertSame($primary, $exception);
                self::assertSame(CredentialDeliveryStatus::EXPIRED, $repository->restart()
                    ->getLatestByUserId($grant->getUserId())->getDelivery()->getStatus());
                self::assertCount(1, $audit->all());
            }
        }
    }

    public function test_delivery_audit_failure_keeps_external_send_and_prior_claim_recoverable(): void
    {
        foreach (
            [CredentialDeliveryOutcome::DELIVERED, CredentialDeliveryOutcome::RETRYABLE_FAILURE,
            CredentialDeliveryOutcome::PERMANENT_FAILURE] as $outcome
        ) {
            $unitOfWork = new InMemoryUnitOfWork();
            $repository = new InMemoryActivationGrantRepository($unitOfWork);
            $grant = $this->grant($repository);
            $audit = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
            $provider = new RecordingCredentialDeliveryProvider([$outcome]);
            $clock = new FixedClock('2030-01-01T01:00:00Z');
            try {
                new DeliverUserInvitationHandler(
                    $repository,
                    $unitOfWork,
                    new PrefixInvitationDeliveryCipher(),
                    $provider,
                    $clock,
                    new InMemoryEventDispatcher(),
                    $audit
                )->handle(CommandMessage::create($this->command('deliver', $grant)));
                self::fail('Audit failure was hidden.');
            } catch (RuntimeException $exception) {
                self::assertSame($audit->failure(), $exception);
            }

            self::assertCount(1, $provider->invocations());
            self::assertSame([], $audit->all());
            self::assertSame(CredentialDeliveryStatus::CLAIMED, $repository->restart()
                ->getLatestByUserId($grant->getUserId())->getDelivery()->getStatus());
            $recoveredAudit = new InMemoryAuditEvidenceRepository($unitOfWork);
            new DeliverUserInvitationHandler(
                $repository->restart(),
                $unitOfWork,
                new PrefixInvitationDeliveryCipher(),
                $provider,
                new FixedClock('2030-01-01T01:05:00Z'),
                new InMemoryEventDispatcher(),
                $recoveredAudit
            )->handle(CommandMessage::create($this->command('deliver', $grant)));
            self::assertCount(2, $provider->invocations());
            self::assertSame(
                $provider->invocations()[0]->getIdempotencyId(),
                $provider->invocations()[1]->getIdempotencyId()
            );
            self::assertCount(1, $recoveredAudit->all());
        }
    }

    public function test_expiry_messages_reject_missing_kind_unsupported_kind_and_wrong_field_types(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $grant = $this->grant(new InMemoryActivationGrantRepository($unitOfWork));
        $payload = $this->command('expire', $grant)->toArray();
        $missingKind = $payload;
        unset($missingKind['actor_kind']);
        foreach ([ExpireInvitationDelivery::class, InvitationDeliveryExpired::class] as $class) {
            foreach (
                [$missingKind, array_replace($payload, ['actor_kind' => 'worker']),
                array_replace($payload, ['actor_id' => 1])] as $invalid
            ) {
                try {
                    $class::fromArray($invalid);
                    self::fail('Invalid current provenance accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }

    private function grant(
        InMemoryActivationGrantRepository $repository,
        string $operation = 'expire',
        ActivationOrigin $origin = ActivationOrigin::REGISTRATION
    ): ActivationGrant {
        $at = new DateTimeImmutable('2030-01-01T00:00:00Z');
        $grant = ActivationGrant::issue(
            $origin,
            UserId::generate(),
            ActivationCredential::fromString('activation'),
            $at,
            $at->modify('+1 day'),
            EmailAddress::fromString('audit@example.test'),
            'ciphertext:activation'
        );
        self::assertTrue($repository->add($grant));
        if ($operation === 'retry') {
            $token = CredentialDeliveryClaimToken::generate();
            $claimed = $grant->claimDelivery($token, $at, $at->modify('+5 minutes'));
            self::assertTrue($repository->replace($grant, $claimed));
            $grant = $claimed->failDelivery($token, $at, CredentialDeliveryFailure::RETRYABLE_PROVIDER);
            self::assertTrue($repository->replace($claimed, $grant));
        }

        return $grant;
    }

    /** @param 'deliver'|'resend'|'retry'|'expire' $operation */
    private function command(string $operation, ActivationGrant $grant): Command
    {
        $actor = SystemId::generate();

        return match ($operation) {
            'deliver' => new DeliverUserInvitation($actor, $grant->getUserId(), $grant->getDelivery()->getId()),
            'resend' => new ResendInvitationDelivery($actor, $grant->getUserId()),
            'retry' => new RetryInvitationDelivery($actor, $grant->getUserId()),
            'expire' => new ExpireInvitationDelivery(
                $actor,
                $grant->getUserId(),
                $grant->getDelivery()->getId(),
                $grant->getExpiresAt()
            )
        };
    }
}
