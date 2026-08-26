<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentProvisioningService;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioned;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentProvisioningFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentNameException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Repository\UnitOfWork;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretCipher;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\FixedHmacSharedSecretGenerator;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(AgentProvisioningService::class)]
#[CoversClass(AgentProvisioningResult::class)]
#[CoversClass(AgentNameException::class)]
#[CoversClass(AgentProvisioningFailed::class)]
#[CoversClass(AgentProvisioned::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentProvisioningServiceTest extends TestCase
{
    public function test_that_a_provisioning_result_cannot_be_serialized_or_expose_its_raw_shared_secret(): void
    {
        $result = new AgentProvisioningResult(
            AgentId::fromString('018f0000-0000-7000-8000-000000000001'),
            AgentCredentialId::fromString('018f0000-0000-7000-8000-000000000002'),
            'shared-secret'
        );
        $serialized = null;

        try {
            $serialized = serialize($result);
            self::fail('Expected provisioning results containing a raw shared secret to reject serialization.');
        } catch (LogicException $logicException) {
            self::assertSame('Agent provisioning results cannot be serialized.', $logicException->getMessage());
        }

        self::assertStringNotContainsString('shared-secret', (string) $serialized);
        self::assertSame('shared-secret', $result->getHmacSharedSecret());
    }

    public function test_that_a_shared_secret_generation_failure_rethrows_unchanged_and_publishes_safe_evidence(): void
    {
        $failure = new RuntimeException('Shared secret generation failed for shared-secret encrypted:shared-secret.');
        $events = new InMemoryEventDispatcher();
        $service = new AgentProvisioningService(
            new InMemoryAgentRepository(),
            new InMemoryAuditEvidenceRepository(),
            new readonly class ($failure) implements HmacSharedSecretGenerator {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function generate(): string
                {
                    throw $this->failure;
                }
            },
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->provision('maintainer-42', 'Production deployment');
            self::fail('Expected the shared secret generation failure to be rethrown.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $this->assertFailureIsSafe($events);
    }

    public function test_that_a_failure_evidence_publication_fault_does_not_replace_the_original_failure(): void
    {
        $failure = new RuntimeException('Shared secret generation failed.');
        $events = new InMemoryEventDispatcher(static function (object $event): void {
            self::assertInstanceOf(AgentProvisioningFailed::class, $event);

            throw new RuntimeException('Failure evidence publication failed.');
        });
        $service = new AgentProvisioningService(
            new InMemoryAgentRepository(),
            new InMemoryAuditEvidenceRepository(),
            new readonly class ($failure) implements HmacSharedSecretGenerator {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function generate(): string
                {
                    throw $this->failure;
                }
            },
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->assertProvisioningFailureIsRethrown($service, $failure);
        self::assertSame([], $events->events());
    }

    public function test_that_shared_secret_encryption_failure_rethrows_unchanged_and_publishes_no_success_event(): void
    {
        $failure = new RuntimeException('Shared secret encryption failed.');
        $events = new InMemoryEventDispatcher();
        $service = new AgentProvisioningService(
            new InMemoryAgentRepository(),
            new InMemoryAuditEvidenceRepository(),
            new FixedHmacSharedSecretGenerator('shared-secret'),
            new readonly class ($failure) implements HmacSharedSecretCipher {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function encrypt(string $hmacSharedSecret): string
                {
                    throw $this->failure;
                }
            },
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->assertProvisioningFailureIsRethrown($service, $failure);
        $this->assertFailureIsSafe($events);
    }

    public function test_that_agent_mutation_failure_rethrows_unchanged_and_publishes_no_success_event(): void
    {
        $failure = new RuntimeException('Agent persistence failed.');
        $events = new InMemoryEventDispatcher();
        $service = new AgentProvisioningService(
            new readonly class ($failure) implements AgentRepository {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function add(Agent $agent): void
                {
                    throw $this->failure;
                }
            },
            new InMemoryAuditEvidenceRepository(),
            new FixedHmacSharedSecretGenerator('shared-secret'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->assertProvisioningFailureIsRethrown($service, $failure);
        $this->assertFailureIsSafe($events);
    }

    public function test_that_audit_failure_rethrows_unchanged_rolls_back_and_publishes_no_success_event(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $service = new AgentProvisioningService(
            new InMemoryAgentRepository($unitOfWork),
            $auditEvidenceRepository,
            new FixedHmacSharedSecretGenerator('shared-secret'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            $unitOfWork,
            $events
        );

        $failure = $this->assertProvisioningFailureIsRethrown($service);

        self::assertSame($auditEvidenceRepository->failure(), $failure);
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertFailureIsSafe($events);
    }

    public function test_that_commit_failure_rethrows_unchanged_and_publishes_no_success_event(): void
    {
        $failure = new RuntimeException('Commit failed.');
        $events = new InMemoryEventDispatcher();
        $service = new AgentProvisioningService(
            new InMemoryAgentRepository(),
            new InMemoryAuditEvidenceRepository(),
            new FixedHmacSharedSecretGenerator('shared-secret'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            new readonly class ($failure) implements UnitOfWork {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function commit(): void
                {
                }

                public function commitTransactional(callable $operation): mixed
                {
                    $operation();

                    throw $this->failure;
                }

                public function isClosed(): bool
                {
                    return false;
                }
            },
            $events
        );

        $this->assertProvisioningFailureIsRethrown($service, $failure);
        $this->assertFailureIsSafe($events);
    }

    public function test_that_agent_events_round_trip_safely_and_reject_missing_data(): void
    {
        $provisioned = new AgentProvisioned(
            AgentId::generate(),
            AgentCredentialId::generate(),
            0,
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
        $failed = new AgentProvisioningFailed('maintainer-42', 'Provisioning failed.');

        self::assertSame(
            $provisioned->toArray(),
            AgentProvisioned::fromArray($provisioned->toArray())->toArray()
        );
        self::assertSame($failed->toArray(), AgentProvisioningFailed::fromArray($failed->toArray())->toArray());
        self::assertStringNotContainsString('shared-secret', serialize($provisioned->toArray()));
        self::assertStringNotContainsString('encrypted:', serialize($failed->toArray()));

        try {
            AgentProvisioned::fromArray([]);
            self::fail('Expected missing provisioned-event data to be rejected.');
        } catch (DomainException $domainException) {
            self::assertInstanceOf(DomainException::class, $domainException);
        }

        $this->expectException(DomainException::class);
        AgentProvisioningFailed::fromArray([]);
    }

    public function test_it_provisions_an_active_agent_and_returns_its_raw_secret_only_after_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(function () use (
            $agentRepository,
            $auditEvidenceRepository,
            $unitOfWork
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertCount(1, $agentRepository->all());
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $service = new AgentProvisioningService(
            $agentRepository,
            $auditEvidenceRepository,
            new FixedHmacSharedSecretGenerator('shared-secret'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            $unitOfWork,
            $events
        );

        $result = $service->provision('maintainer-42', '  Production deployment  ');

        self::assertSame('shared-secret', $result->getHmacSharedSecret());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertCount(1, $agentRepository->all());
        $agent = $agentRepository->all()[0];
        self::assertSame('Production deployment', $agent->getName()->toString());
        self::assertSame(AgentState::ACTIVE, $agent->getState());
        self::assertSame(0, $agent->getCredentialRevision());
        self::assertSame('encrypted:shared-secret', $agent->getEncryptedHmacSharedSecretEnvelope());
        self::assertSame($agent->getId(), $result->getAgentId());
        self::assertSame($agent->getCredentialId(), $result->getCredentialId());
        self::assertCount(1, $auditEvidenceRepository->all());
        self::assertSame('maintainer-42', $auditEvidenceRepository->all()[0]->actorId());
        self::assertSame('agent.provisioned', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($agent->getId(), $auditEvidenceRepository->all()[0]->subjectId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(AgentProvisioned::class, $events->events()[0]);
        self::assertSame($agent->getId(), $events->events()[0]->getAgentId());
        self::assertSame($agent->getCredentialId(), $events->events()[0]->getCredentialId());
        self::assertSame(0, $events->events()[0]->getCredentialRevision());
        self::assertEquals(
            new DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            $events->events()[0]->getProvisionedAt()
        );
        self::assertSame(
            ['agent_id', 'credential_id', 'credential_revision', 'provisioned_at'],
            array_keys($events->events()[0]->toArray())
        );
    }

    public function test_it_rejects_an_invalid_agent_name_without_persisting_or_publishing_a_success_event(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $service = new AgentProvisioningService(
            $agentRepository,
            $auditEvidenceRepository,
            new readonly class implements HmacSharedSecretGenerator {
                public function generate(): string
                {
                    throw new LogicException('Credential generation must not run for an invalid Agent name.');
                }
            },
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:00:00+00:00')),
            $unitOfWork,
            $events
        );

        try {
            $service->provision('maintainer-42', '   ');
            self::fail('Expected an invalid Agent name to be rejected.');
        } catch (AgentNameException) {
            self::addToAssertionCount(1);
        }

        self::assertSame([], $agentRepository->all());
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertFailureIsSafe($events);
    }

    public function test_audit_evidence_records_user_and_agent_subjects_without_context(): void
    {
        $userId = UserId::fromString('018f0000-0000-7000-8000-000000000001');
        $agentId = AgentId::fromString('018f0000-0000-7000-8000-000000000002');
        $userEvidence = AuditEvidence::record('maintainer-42', 'user.invited', $userId);
        $agentEvidence = AuditEvidence::agentProvisioned('maintainer-42', $agentId);

        self::assertSame($userId, $userEvidence->subjectId());
        self::assertSame($agentId, $agentEvidence->subjectId());
        self::assertSame([], $agentEvidence->context());
    }

    private function assertFailureIsSafe(InMemoryEventDispatcher $events): void
    {
        self::assertCount(1, $events->events());
        self::assertInstanceOf(AgentProvisioningFailed::class, $events->events()[0]);
        self::assertSame('maintainer-42', $events->events()[0]->getActorId());
        self::assertSame(['actor_id', 'error_message'], array_keys($events->events()[0]->toArray()));
        self::assertSame('Agent provisioning failed.', $events->events()[0]->getErrorMessage());
        self::assertStringNotContainsString('shared-secret', serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString('encrypted:', serialize($events->events()[0]->toArray()));
    }

    private function assertProvisioningFailureIsRethrown(
        AgentProvisioningService $service,
        ?Throwable $expectedFailure = null
    ): Throwable {
        try {
            $service->provision('maintainer-42', 'Production deployment');
            self::fail('Expected the provisioning failure to be rethrown.');
        } catch (Throwable $throwable) {
            if ($expectedFailure instanceof Throwable) {
                self::assertSame($expectedFailure, $throwable);
            }

            return $throwable;
        }
    }
}
