<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialRotationResult;
use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRevoked;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
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

#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentCredentialRotationResult::class)]
#[CoversClass(AgentCredentialRotated::class)]
#[CoversClass(AgentCredentialRevoked::class)]
#[CoversClass(AgentCredentialLifecycleFailed::class)]
#[CoversClass(AgentCredentialException::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentCredentialLifecycleServiceTest extends TestCase
{
    public function test_it_rotates_one_current_agent_credential_after_commit(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agentId = AgentId::generate();
        $currentCredentialId = AgentCredentialId::generate();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $agentRepository->add(Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $currentCredentialId,
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(function () use (
            $unitOfWork,
            $agentRepository,
            $auditEvidenceRepository
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertSame(1, $agentRepository->all()[0]->getCredentialRevision());
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $service = new AgentCredentialLifecycleService(
            $agentRepository,
            $auditEvidenceRepository,
            new FixedHmacSharedSecretGenerator('rotated-secret'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            $unitOfWork,
            $events
        );

        $result = $service->rotate('maintainer-42', $agentId, $currentCredentialId);

        self::assertSame('rotated-secret', $result->getHmacSharedSecret());
        self::assertSame($agentId, $result->getAgentId());
        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame($result->getCredentialId(), $agentRepository->all()[0]->getCredentialId());
        self::assertSame(1, $agentRepository->all()[0]->getCredentialRevision());
        self::assertSame(
            'encrypted:rotated-secret',
            $agentRepository->all()[0]->getEncryptedHmacSharedSecretEnvelope()
        );
        self::assertSame('agent.credential_rotated', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($agentId, $auditEvidenceRepository->all()[0]->subjectId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(AgentCredentialRotated::class, $events->events()[0]);
        self::assertSame($result->getCredentialId(), $events->events()[0]->getCredentialId());
        self::assertSame(1, $events->events()[0]->getCredentialRevision());
        self::assertStringNotContainsString('rotated-secret', serialize($events->events()[0]->toArray()));
    }

    public function test_it_rejects_a_stale_credential_without_replacing_or_publishing_secret_bearing_state(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agentId = AgentId::generate();
        $currentCredentialId = AgentCredentialId::generate();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $agentRepository->add(Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $currentCredentialId,
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher();
        $service = new AgentCredentialLifecycleService(
            $agentRepository,
            $auditEvidenceRepository,
            new FixedHmacSharedSecretGenerator('must-not-be-exposed'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            $unitOfWork,
            $events
        );

        $this->expectException(AgentCredentialException::class);

        try {
            $service->rotate('maintainer-42', $agentId, AgentCredentialId::generate());
        } finally {
            self::assertSame($currentCredentialId, $agentRepository->all()[0]->getCredentialId());
            self::assertSame(0, $agentRepository->all()[0]->getCredentialRevision());
            self::assertSame([], $auditEvidenceRepository->all());
            $this->assertLifecycleFailureIsSafe($events);
        }
    }

    public function test_that_a_rotation_result_cannot_be_serialized(): void
    {
        $result = new AgentCredentialRotationResult(
            AgentId::generate(),
            AgentCredentialId::generate(),
            'rotated-secret'
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Agent credential rotation results cannot be serialized.');
        serialize($result);
    }

    public function test_that_rotation_failure_rethrows_unchanged_and_publishes_safe_failure_evidence(): void
    {
        $failure = new RuntimeException('Credential encryption failed for rotated-secret encrypted:rotated-secret.');
        $events = new InMemoryEventDispatcher();
        $agentId = AgentId::generate();
        $credentialId = AgentCredentialId::generate();
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add(Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $credentialId,
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $service = new AgentCredentialLifecycleService(
            $agentRepository,
            new InMemoryAuditEvidenceRepository(),
            new FixedHmacSharedSecretGenerator('rotated-secret'),
            new readonly class ($failure) implements HmacSharedSecretCipher {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function encrypt(string $hmacSharedSecret): string
                {
                    throw $this->failure;
                }
            },
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->rotate('maintainer-42', $agentId, $credentialId);
            self::fail('Expected the rotation failure to be rethrown.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        $this->assertLifecycleFailureIsSafe($events);
    }

    public function test_that_rotation_of_an_unknown_agent_is_rejected_safely(): void
    {
        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            new InMemoryAgentRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(LogicException::class);

        try {
            $service->rotate('maintainer-42', AgentId::generate(), AgentCredentialId::generate());
        } finally {
            $this->assertLifecycleFailureIsSafe($events);
        }
    }

    public function test_it_terminally_revokes_an_agent_after_committing_safe_audit_evidence(): void
    {
        $unitOfWork = new InMemoryUnitOfWork();
        $agentId = AgentId::generate();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $agentRepository->add(Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        ));
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork);
        $events = new InMemoryEventDispatcher(function () use (
            $unitOfWork,
            $agentRepository,
            $auditEvidenceRepository
        ): void {
            self::assertTrue($unitOfWork->transactionCompleted);
            self::assertSame(AgentState::REVOKED, $agentRepository->all()[0]->getState());
            self::assertCount(1, $auditEvidenceRepository->all());
        });
        $service = new AgentCredentialLifecycleService(
            $agentRepository,
            $auditEvidenceRepository,
            new FixedHmacSharedSecretGenerator('must-not-be-generated'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            $unitOfWork,
            $events
        );

        $service->revoke('maintainer-42', $agentId);

        self::assertSame(1, $unitOfWork->transactions);
        self::assertSame(AgentState::REVOKED, $agentRepository->all()[0]->getState());
        self::assertSame('agent.credential_revoked', $auditEvidenceRepository->all()[0]->action());
        self::assertSame($agentId, $auditEvidenceRepository->all()[0]->subjectId());
        self::assertCount(1, $events->events());
        self::assertInstanceOf(AgentCredentialRevoked::class, $events->events()[0]);
        self::assertSame($agentId, $events->events()[0]->getAgentId());
        self::assertStringNotContainsString('current-secret', serialize($events->events()[0]->toArray()));

        $this->expectException(AgentCredentialException::class);

        try {
            $service->rotate('maintainer-42', $agentId, $agentRepository->all()[0]->getCredentialId());
        } finally {
            self::assertCount(2, $events->events());
            self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $events->events()[1]);
            self::assertSame('Agent credential lifecycle failed.', $events->events()[1]->getErrorMessage());
        }
    }

    public function test_that_a_replacement_failure_rethrows_unchanged_and_publishes_no_success_event(): void
    {
        $failure = new RuntimeException('Agent replacement failed.');
        $events = new InMemoryEventDispatcher();
        $service = new AgentCredentialLifecycleService(
            new readonly class ($failure) implements AgentRepository {
                public function __construct(private RuntimeException $failure)
                {
                }

                public function add(Agent $agent): void
                {
                }

                public function getById(AgentId $id): ?Agent
                {
                    if ($id->toString() === '') {
                        return null;
                    }

                    return Agent::provision(
                        $id,
                        AgentName::fromString('Production deployment'),
                        AgentCredentialId::generate(),
                        'encrypted:current-secret',
                        new DateTimeImmutable('2026-08-25T12:00:00+00:00')
                    );
                }

                public function replace(Agent $expected, Agent $replacement): bool
                {
                    throw $this->failure;
                }
            },
            new InMemoryAuditEvidenceRepository(),
            new FixedHmacSharedSecretGenerator('must-not-be-generated'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectExceptionObject($failure);

        try {
            $service->revoke('maintainer-42', AgentId::generate());
        } finally {
            $this->assertLifecycleFailureIsSafe($events);
        }
    }

    public function test_that_audit_and_commit_failures_rethrow_unchanged_and_publish_no_success_event(): void
    {
        $agentId = AgentId::generate();
        $unitOfWork = new InMemoryUnitOfWork();
        $agentRepository = new InMemoryAgentRepository($unitOfWork);
        $agentRepository->add($this->agent($agentId));

        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository($unitOfWork, failAfterSave: true);
        $events = new InMemoryEventDispatcher();
        $service = $this->service($agentRepository, $auditEvidenceRepository, $unitOfWork, $events);

        try {
            $service->revoke('maintainer-42', $agentId);
            self::fail('Expected the audit failure to be rethrown.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($auditEvidenceRepository->failure(), $runtimeException);
        }

        self::assertSame(AgentState::ACTIVE, $agentRepository->all()[0]->getState());
        self::assertSame([], $auditEvidenceRepository->all());
        $this->assertLifecycleFailureIsSafe($events);

        $commitFailure = new RuntimeException('Commit failed.');
        $commitEvents = new InMemoryEventDispatcher();
        $commitAgentId = AgentId::generate();
        $commitAgentRepository = new InMemoryAgentRepository();
        $commitAgentRepository->add($this->agent($commitAgentId));

        $commitService = $this->service(
            $commitAgentRepository,
            new InMemoryAuditEvidenceRepository(),
            new readonly class ($commitFailure) implements UnitOfWork {
                public function __construct(private RuntimeException $commitFailure)
                {
                }

                public function commit(): void
                {
                }

                public function commitTransactional(callable $operation): mixed
                {
                    $operation();

                    throw $this->commitFailure;
                }

                public function isClosed(): bool
                {
                    return false;
                }
            },
            $commitEvents
        );

        $this->expectExceptionObject($commitFailure);

        try {
            $commitService->revoke('maintainer-42', $commitAgentId);
        } finally {
            $this->assertLifecycleFailureIsSafe($commitEvents);
        }
    }

    public function test_that_revocation_of_an_unknown_agent_is_rejected_safely(): void
    {
        $events = new InMemoryEventDispatcher();
        $service = $this->service(
            new InMemoryAgentRepository(),
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectException(LogicException::class);

        try {
            $service->revoke('maintainer-42', AgentId::generate());
        } finally {
            $this->assertLifecycleFailureIsSafe($events);
        }
    }

    public function test_that_stale_repository_replacements_are_rejected_for_both_lifecycle_operations(): void
    {
        $agentId = AgentId::generate();
        $credentialId = AgentCredentialId::generate();
        $repository = new readonly class ($this->agent($agentId, $credentialId)) implements AgentRepository {
            public function __construct(private Agent $agent)
            {
            }

            public function add(Agent $agent): void
            {
            }

            public function getById(AgentId $id): ?Agent
            {
                return $id->equals($this->agent->getId()) ? $this->agent : null;
            }

            public function replace(Agent $expected, Agent $replacement): bool
            {
                return false;
            }
        };

        foreach (
            [
                static fn (AgentCredentialLifecycleService $service): AgentCredentialRotationResult => $service->rotate(
                    'maintainer-42',
                    $agentId,
                    $credentialId
                ),
                static fn (AgentCredentialLifecycleService $service): null => $service->revoke(
                    'maintainer-42',
                    $agentId
                ),
            ] as $operation
        ) {
            $events = new InMemoryEventDispatcher();
            $service = $this->service(
                $repository,
                new InMemoryAuditEvidenceRepository(),
                new InMemoryUnitOfWork(),
                $events
            );

            try {
                $operation($service);
                self::fail('Expected the stale repository replacement to be rejected.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }

            $this->assertLifecycleFailureIsSafe($events);
        }
    }

    public function test_that_a_revocation_event_round_trips_safely_and_event_publication_failure_rethrows(): void
    {
        $event = new AgentCredentialRevoked(
            AgentId::generate(),
            new DateTimeImmutable('2026-08-25T12:05:00+00:00')
        );

        self::assertSame($event->toArray(), AgentCredentialRevoked::fromArray($event->toArray())->toArray());
        self::assertStringNotContainsString('secret', serialize($event->toArray()));
        self::assertEquals(new DateTimeImmutable('2026-08-25T12:05:00+00:00'), $event->getRevokedAt());

        try {
            AgentCredentialRevoked::fromArray([]);
            self::fail('Expected missing event data to be rejected.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        $failure = new RuntimeException('Event publication failed.');
        $events = new InMemoryEventDispatcher(static function (object $event) use ($failure): void {
            if ($event instanceof AgentCredentialRevoked) {
                throw $failure;
            }
        });
        $agentId = AgentId::generate();
        $agentRepository = new InMemoryAgentRepository();
        $agentRepository->add($this->agent($agentId));

        $service = $this->service(
            $agentRepository,
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        $this->expectExceptionObject($failure);

        try {
            $service->revoke('maintainer-42', $agentId);
        } finally {
            $this->assertLifecycleFailureIsSafe($events);
        }
    }

    public function test_that_failure_evidence_publication_fault_does_not_replace_the_original_failure(): void
    {
        $failure = new RuntimeException('Agent replacement failed.');
        $agentId = AgentId::generate();
        $events = new InMemoryEventDispatcher(static function (object $event): void {
            self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $event);

            throw new RuntimeException('Failure evidence publication failed.');
        });
        $service = $this->service(
            new readonly class ($failure, $agentId) implements AgentRepository {
                public function __construct(
                    private RuntimeException $failure,
                    private AgentId $agentId
                ) {
                }

                public function add(Agent $agent): void
                {
                }

                public function getById(AgentId $id): ?Agent
                {
                    if (!$id->equals($this->agentId)) {
                        return null;
                    }

                    return Agent::provision(
                        $id,
                        AgentName::fromString('Production deployment'),
                        AgentCredentialId::generate(),
                        'encrypted:current-secret',
                        new DateTimeImmutable('2026-08-25T12:00:00+00:00')
                    );
                }

                public function replace(Agent $expected, Agent $replacement): bool
                {
                    throw $this->failure;
                }
            },
            new InMemoryAuditEvidenceRepository(),
            new InMemoryUnitOfWork(),
            $events
        );

        try {
            $service->revoke('maintainer-42', $agentId);
            self::fail('Expected the revocation failure to be rethrown.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($failure, $runtimeException);
        }

        self::assertSame([], $events->events());
    }

    public function test_that_lifecycle_failure_event_round_trips_safely_and_rejects_missing_data(): void
    {
        $event = new AgentCredentialLifecycleFailed('maintainer-42', 'Agent credential lifecycle failed.');

        self::assertSame(
            $event->toArray(),
            AgentCredentialLifecycleFailed::fromArray($event->toArray())->toArray()
        );
        self::assertStringNotContainsString('rotated-secret', serialize($event->toArray()));
        self::assertStringNotContainsString('encrypted:', serialize($event->toArray()));

        $this->expectException(DomainException::class);
        AgentCredentialLifecycleFailed::fromArray([]);
    }

    public function test_that_rotated_event_round_trips_and_exposes_its_safe_metadata(): void
    {
        $event = new AgentCredentialRotated(
            AgentId::generate(),
            AgentCredentialId::generate(),
            3,
            new DateTimeImmutable('2026-08-25T12:05:00+00:00')
        );

        $roundTripped = AgentCredentialRotated::fromArray($event->toArray());

        self::assertEquals($event->getAgentId(), $roundTripped->getAgentId());
        self::assertEquals($event->getCredentialId(), $roundTripped->getCredentialId());
        self::assertSame(3, $roundTripped->getCredentialRevision());
        self::assertEquals(new DateTimeImmutable('2026-08-25T12:05:00+00:00'), $roundTripped->getRotatedAt());

        $this->expectException(DomainException::class);
        AgentCredentialRotated::fromArray([]);
    }

    private function assertLifecycleFailureIsSafe(InMemoryEventDispatcher $events): void
    {
        self::assertCount(1, $events->events());
        self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $events->events()[0]);
        self::assertSame('maintainer-42', $events->events()[0]->getActorId());
        self::assertSame(['actor_id', 'error_message'], array_keys($events->events()[0]->toArray()));
        self::assertSame('Agent credential lifecycle failed.', $events->events()[0]->getErrorMessage());
        self::assertStringNotContainsString('rotated-secret', serialize($events->events()[0]->toArray()));
        self::assertStringNotContainsString('encrypted:', serialize($events->events()[0]->toArray()));
    }

    private function agent(AgentId $agentId, ?AgentCredentialId $credentialId = null): Agent
    {
        return Agent::provision(
            $agentId,
            AgentName::fromString('Production deployment'),
            $credentialId ?? AgentCredentialId::generate(),
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }

    private function service(
        AgentRepository $agentRepository,
        InMemoryAuditEvidenceRepository $auditEvidenceRepository,
        UnitOfWork $unitOfWork,
        InMemoryEventDispatcher $events
    ): AgentCredentialLifecycleService {
        return new AgentCredentialLifecycleService(
            $agentRepository,
            $auditEvidenceRepository,
            new FixedHmacSharedSecretGenerator('must-not-be-generated'),
            new FixedHmacSharedSecretCipher('encrypted:'),
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            $unitOfWork,
            $events
        );
    }
}
