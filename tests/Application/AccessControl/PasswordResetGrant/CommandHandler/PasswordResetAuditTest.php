<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler;

use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\DeliverPasswordResetHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\ExpirePasswordResetDeliveryHandler;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\CommandHandler\RequestPasswordResetHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditGrantState;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryStatus;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\DeliverPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\ExpirePasswordResetDelivery;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command\RequestPasswordReset;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event\PasswordResetDeliveryExpired;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetGrantRepository;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\CommandMessage;
use Fight\Common\Domain\Messaging\Event\CommandFailedEvent;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\CredentialDelivery\Service\RecordingCredentialDeliveryProvider;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\RecoveryFixture;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\FixedPasswordResetCredentialGenerator;
use Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PrefixPasswordResetDeliveryCipher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(RequestPasswordResetHandler::class)]
#[CoversClass(DeliverPasswordResetHandler::class)]
#[CoversClass(ExpirePasswordResetDeliveryHandler::class)]
#[CoversClass(ExpirePasswordResetDelivery::class)]
#[CoversClass(PasswordResetDeliveryExpired::class)]
#[CoversClass(AuditEvidence::class)]
final class PasswordResetAuditTest extends TestCase
{
    public function test_all_three_handlers_accept_omitted_and_explicit_null_audit_storage(): void
    {
        foreach (['request', 'deliver', 'expire'] as $operation) {
            foreach ([[], [null]] as $optional) {
                $fixture = new RecoveryFixture();
                $fixture->request();
                $grant = $fixture->grant();
                $before = $fixture->auditEvidenceRepository->all();
                $events = new InMemoryEventDispatcher();
                $provider = new RecordingCredentialDeliveryProvider();
                $clock = new FixedClock($fixture->now);
                $handler = match ($operation) {
                    'request' => new RequestPasswordResetHandler(
                        $fixture->userRepository,
                        $fixture->passwordResetGrantRepository,
                        $fixture->unitOfWork,
                        new FixedPasswordResetCredentialGenerator('replacement'),
                        new PrefixPasswordResetDeliveryCipher(),
                        $clock,
                        $events,
                        ...$optional
                    ),
                    'deliver' => new DeliverPasswordResetHandler(
                        $fixture->passwordResetGrantRepository,
                        $fixture->unitOfWork,
                        new PrefixPasswordResetDeliveryCipher(),
                        $provider,
                        $clock,
                        $events,
                        $fixture->userRepository,
                        ...$optional
                    ),
                    'expire' => new ExpirePasswordResetDeliveryHandler(
                        $fixture->passwordResetGrantRepository,
                        $fixture->unitOfWork,
                        $events,
                        ...$optional
                    )
                };
                $command = match ($operation) {
                    'request' => new RequestPasswordReset($fixture->user()->getEmail()),
                    'deliver' => new DeliverPasswordReset(
                        AnonymousId::generate(),
                        $fixture->userId, $grant->getDelivery()->getId()
                    ),
                    'expire' => new ExpirePasswordResetDelivery(
                        SystemId::generate(),
                        $fixture->userId,
                        $grant->getDelivery()->getId(),
                        $grant->getExpiresAt()
                    )
                };
                $handler->handle(CommandMessage::create($command));
                self::assertSame($before, $fixture->auditEvidenceRepository->all());
                self::assertCount(1, $events->events());
                self::assertSame(match ($operation) {
                    'request' => CredentialDeliveryStatus::PENDING,
                    'deliver' => CredentialDeliveryStatus::DELIVERED,
                    'expire' => CredentialDeliveryStatus::EXPIRED
                }, $fixture->grant()->getDelivery()->getStatus());
                self::assertCount((int) ($operation === 'deliver'), $provider->invocations());
            }
        }
    }

    public function test_late_request_audit_failure_rolls_back_predecessor_and_successor(): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $before = $fixture->passwordResetGrantRepository->all();
        $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork, failAfterSave: true);
        try {
            new RequestPasswordResetHandler(
                $fixture->userRepository,
                $fixture->passwordResetGrantRepository,
                $fixture->unitOfWork,
                new FixedPasswordResetCredentialGenerator('replacement'),
                new PrefixPasswordResetDeliveryCipher(),
                new FixedClock($fixture->now),
                new InMemoryEventDispatcher(),
                $audit
            )->handle(CommandMessage::create(new RequestPasswordReset($fixture->user()->getEmail())));
            self::fail('Required audit fault was hidden.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($audit->failure(), $runtimeException);
        }

        self::assertSame($before, $fixture->connection()->passwordResetGrantRepository->all());
        self::assertSame([], $audit->all());
        self::assertTrue($fixture->grant()->isIssued());
        self::assertTrue($fixture->grant()->getDelivery()->hasRecoverableMaterial());
    }

    public function test_delivery_preserves_explicit_origin_and_records_only_the_real_outcome(): void
    {
        foreach ([UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate()] as $actor) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $grant = $fixture->grant();
            $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork);
            $message = CommandMessage::create(new DeliverPasswordReset(
                $actor,
                $fixture->userId,
                $grant->getDelivery()->getId()
            ));
            new DeliverPasswordResetHandler(
                $fixture->passwordResetGrantRepository,
                $fixture->unitOfWork,
                new PrefixPasswordResetDeliveryCipher(),
                new RecordingCredentialDeliveryProvider(),
                new FixedClock($fixture->now),
                new InMemoryEventDispatcher(),
                $fixture->userRepository,
                $audit
            )->handle($message);
            self::assertCount(1, $audit->all());
            $evidence = $audit->all()[0];
            self::assertSame($actor, $evidence->actorId());
            self::assertSame(AuditAction::PASSWORD_RESET_DELIVERY_CONFIRMED, $evidence->action());
            self::assertEquals($message->id(), $evidence->getCorrelation()->getMessageId());
            self::assertEquals($fixture->now, $evidence->getOccurredAt());
            $facts = $evidence->getFacts();
            self::assertInstanceOf(AuditGrantOutcome::class, $facts);
            self::assertSame($grant->getRevision() + 1, $facts->getBefore()->toArray()['revision']);
            self::assertEquals(AuditGrantState::fromGrant($fixture->grant()), $facts->getAfter());
            self::assertSame(CredentialDeliveryStatus::DELIVERED, $facts->getDeliveryStatus());
            self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        }
    }

    public function test_expiry_records_exact_typed_outcome_once_and_keeps_recovery_authority(): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $grant = $fixture->grant();
        $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork);
        $events = new InMemoryEventDispatcher();
        $actor = AgentId::generate();
        $at = $grant->getExpiresAt()->modify('+1 microsecond');
        $message = CommandMessage::create(new ExpirePasswordResetDelivery(
            $actor,
            $fixture->userId,
            $grant->getDelivery()->getId(),
            $at
        ));
        $handler = new ExpirePasswordResetDeliveryHandler(
            $fixture->passwordResetGrantRepository,
            $fixture->unitOfWork,
            $events,
            $audit
        );
        $handler->handle($message);

        $stored = $fixture->connection()->passwordResetGrantRepository->getLatestByUserId($fixture->userId);
        self::assertTrue($stored->isIssued());
        self::assertEquals($grant->getAuthority(), $stored->getAuthority());
        self::assertNull($stored->getDelivery()->getEncryptedMaterial());
        self::assertCount(1, $audit->all());
        $evidence = $audit->all()[0];
        self::assertSame(AuditAction::PASSWORD_RESET_DELIVERY_EXPIRED, $evidence->action());
        self::assertSame($actor, $evidence->actorId());
        self::assertEquals($at, $evidence->getOccurredAt());
        self::assertEquals($fixture->userId, $evidence->getSubject()->getId());
        self::assertEquals($message->id(), $evidence->getCorrelation()->getMessageId());
        self::assertEquals(new AuditGrantOutcome(
            AuditGrantState::fromGrant($grant),
            AuditGrantState::fromGrant($stored),
            CredentialDeliveryStatus::EXPIRED
        ), $evidence->getFacts());
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        $invalid = $evidence->toArray();
        $invalid['facts']['delivery_status'] = CredentialDeliveryStatus::DELIVERED->value;
        try {
            AuditEvidence::fromArray($invalid);
            self::fail('Expiry evidence accepted another outcome status.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        self::assertStringNotContainsString('recovery-fixture', serialize($evidence->toArray()));
        self::assertStringNotContainsString('example.test', serialize($evidence->toArray()));
        $handler->handle($message);
        self::assertCount(1, $audit->all());
        self::assertCount(1, $events->events());
    }

    public function test_expiry_no_change_and_failed_cas_produce_no_evidence(): void
    {
        foreach (['early', 'missing', 'owner', 'cas', 'revoked'] as $case) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $grant = $fixture->grant();
            if ($case === 'revoked') {
                self::assertTrue($fixture->passwordResetGrantRepository->replace(
                    $grant,
                    $grant->revoke($fixture->now)
                ));
            }

            $before = $fixture->passwordResetGrantRepository->all();
            $repository = $fixture->passwordResetGrantRepository;
            if ($case === 'cas') {
                $repository = $this->createMock(PasswordResetGrantRepository::class);
                $repository->method('getByDeliveryId')->willReturn($grant);
                $repository->method('getLatestByUserId')->willReturn($grant);
                $repository->expects(self::once())->method('replace')->willReturn(false);
            }

            $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork);
            $events = new InMemoryEventDispatcher();
            new ExpirePasswordResetDeliveryHandler($repository, $fixture->unitOfWork, $events, $audit)->handle(
                CommandMessage::create(new ExpirePasswordResetDelivery(
                    SystemId::generate(),
                    $case === 'owner' ? UserId::generate() : $fixture->userId,
                    $case === 'missing' ? PasswordResetDeliveryId::generate() : $grant->getDelivery()->getId(),
                    $case === 'early' ? $grant->getExpiresAt()->modify('-1 microsecond') : $grant->getExpiresAt()
                ))
            );
            self::assertSame($before, $fixture->passwordResetGrantRepository->all());
            self::assertSame([], $audit->all());
            self::assertSame([], $events->events());
        }
    }

    public function test_expiry_faults_preserve_primary_and_truthful_commit_outcome(): void
    {
        foreach (['audit', 'commit', 'uncertain', 'publication'] as $phase) {
            $fixture = new RecoveryFixture();
            $fixture->request();
            $grant = $fixture->grant();
            $audit = new InMemoryAuditEvidenceRepository($fixture->unitOfWork, failAfterSave: $phase === 'audit');
            $primary = new RuntimeException('Synthetic primary fault.');
            if ($phase === 'commit') {
                $fixture->unitOfWork->beforeCommit = static function () use ($primary): void {
                    throw $primary;
                };
            } elseif ($phase === 'uncertain') {
                $fixture->unitOfWork->afterCommit = static function () use ($primary): void {
                    throw $primary;
                };
            }

            $attempts = [];
            $events = new InMemoryEventDispatcher(static function ($event) use (&$attempts, $primary): void {
                $attempts[] = $event;
                if ($event instanceof PasswordResetDeliveryExpired) {
                    throw $primary;
                }

                throw new RuntimeException('Secondary notification fault.');
            });
            try {
                new ExpirePasswordResetDeliveryHandler(
                    $fixture->passwordResetGrantRepository,
                    $fixture->unitOfWork,
                    $events,
                    $audit
                )->handle(CommandMessage::create(new ExpirePasswordResetDelivery(
                    SystemId::generate(),
                    $fixture->userId,
                    $grant->getDelivery()->getId(),
                    $grant->getExpiresAt()
                )));
                self::fail('Persistence/publication fault was hidden.');
            } catch (RuntimeException $exception) {
                self::assertSame($phase === 'audit' ? $audit->failure() : $primary, $exception);
            }

            $committed = in_array($phase, ['uncertain', 'publication'], true);
            self::assertCount((int) $committed, $audit->all());
            self::assertSame(!$committed, $fixture->grant()->getDelivery()->hasRecoverableMaterial());
            self::assertSame($grant->getRevision() + (int) $committed, $fixture->grant()->getRevision());
            self::assertInstanceOf(CommandFailedEvent::class, $attempts[array_key_last($attempts)]);
            self::assertSame($phase === 'publication' ? 2 : 1, count($attempts));
        }
    }

    public function test_expiry_messages_preserve_actor_kind_and_reject_untyped_or_malformed_provenance(): void
    {
        $fixture = new RecoveryFixture();
        $fixture->request();

        $grant = $fixture->grant();
        foreach ([UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate()] as $actor) {
            foreach ([ExpirePasswordResetDelivery::class, PasswordResetDeliveryExpired::class] as $class) {
                $message = new $class($actor, $fixture->userId, $grant->getDelivery()->getId(), $grant->getExpiresAt());
                self::assertEquals($message, $class::fromArray($message->toArray()));
                self::assertSame($actor, $message->getActorId());
                foreach (['actor_kind', 'actor_id', 'user_id', 'password_reset_delivery_id', 'occurred_at'] as $key) {
                    foreach (['missing', 'type'] as $fault) {
                        $data = $message->toArray();
                        if ($fault === 'missing') {
                            unset($data[$key]);
                        } else {
                            $data[$key] = [];
                        }

                        try {
                            $class::fromArray($data);
                            self::fail('Malformed expiry payload was accepted.');
                        } catch (DomainException) {
                            self::addToAssertionCount(1);
                        }
                    }
                }

                $data = $message->toArray();
                $data['actor_kind'] = 'worker';
                try {
                    $class::fromArray($data);
                    self::fail('Service-name provenance was accepted.');
                } catch (DomainException) {
                    self::addToAssertionCount(1);
                }
            }
        }
    }
}
