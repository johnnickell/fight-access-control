<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialLifecycleService;
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
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Repository\InMemoryAgentRepository;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use Fight\Test\AccessControl\Application\AccessControl\User\InMemoryUnitOfWork;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentCredentialRotated::class)]
#[CoversClass(AgentCredentialRevoked::class)]
#[CoversClass(AgentCredentialLifecycleFailed::class)]
#[CoversClass(AgentCredentialException::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentCredentialLifecycleServiceTest extends TestCase
{
    public function test_legacy_raw_rotation_rejects_without_any_effect_even_for_legacy_agents(): void
    {
        $transaction = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($transaction);
        $agent = $this->agent();
        $agents->add($agent);
        $audit = new InMemoryAuditEvidenceRepository($transaction);
        $events = new InMemoryEventDispatcher();
        try {
            $this->service($agents, $audit, $transaction, $events)
                ->rotate('maintainer-42', $agent->getId(), $agent->getCredentialId());
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
        }

        self::assertSame(0, $transaction->transactions);
        self::assertSame([$agent], $agents->all());
        self::assertSame([], $audit->all());
        self::assertSame([], $events->events());
    }

    public function test_legacy_revocation_commits_before_publication_and_remains_terminal(): void
    {
        $transaction = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($transaction);
        $agent = $this->agent();
        $agents->add($agent);
        $audit = new InMemoryAuditEvidenceRepository($transaction);
        $events = new InMemoryEventDispatcher(static function (object $event) use (
            $transaction,
            $agents,
            $audit
        ): void {
            if ($event instanceof AgentCredentialRevoked) {
                self::assertFalse($transaction->transactionActive);
                self::assertSame(AgentState::REVOKED, $agents->all()[0]->getState());
                self::assertSame('agent.credential_revoked', $audit->all()[0]->action());
            }
        });
        $service = $this->service($agents, $audit, $transaction, $events);
        $service->revoke('maintainer-42', $agent->getId());
        self::assertCount(1, $audit->all());
        self::assertSame($agent->getId(), $audit->all()[0]->subjectId());
        self::assertInstanceOf(AgentCredentialRevoked::class, $events->events()[0]);
        try {
            $service->revoke('maintainer-42', $agent->getId());
            self::fail('Revocation must remain terminal.');
        } catch (AgentCredentialException) {
            self::assertCount(1, $audit->all());
            self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $events->events()[1]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        foreach (['absent', 'stale', 'repository', 'audit', 'commit', 'publication', 'both publishers'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('failures')]
    public function test_revocation_preserves_failure_and_publication_semantics(string $case): void
    {
        $transaction = new InMemoryUnitOfWork();
        $agents = new InMemoryAgentRepository($transaction);
        $agent = $this->agent();
        $agents->add($agent);
        $audit = new InMemoryAuditEvidenceRepository($transaction, failAfterSave: $case === 'audit');
        $fault = new RuntimeException('Original failure');
        $repository = $agents;
        if (in_array($case, ['absent', 'stale', 'repository'], true)) {
            $repository = $this->createStub(AgentRepository::class);
            $repository->method('getOperationContract')->willReturn($agents->getOperationContract());
            $repository->method('getById')->willReturn($case === 'absent' ? null : $agent);
            if ($case === 'repository') {
                $repository->method('replace')->willThrowException($fault);
            } else {
                $repository->method('replace')->willReturn(false);
            }
        }

        $transaction->failNextCommit = $case === 'commit';
        $events = new InMemoryEventDispatcher(static function (object $event) use ($case, $fault): void {
            if ($case === 'both publishers' || ($case === 'publication' && $event instanceof AgentCredentialRevoked)) {
                throw $fault;
            }
        });
        try {
            $this->service($repository, $audit, $transaction, $events)->revoke('maintainer-42', $agent->getId());
            self::fail('Expected revocation failure.');
        } catch (LogicException | RuntimeException $failure) {
            if (in_array($case, ['repository', 'publication', 'both publishers'], true)) {
                self::assertSame($fault, $failure);
            } elseif ($case === 'audit') {
                self::assertSame($audit->failure(), $failure);
            }
        }

        $committed = in_array($case, ['publication', 'both publishers'], true);
        self::assertCount((int) $committed, $audit->all());
        self::assertSame($committed ? AgentState::REVOKED : AgentState::ACTIVE, $agents->all()[0]->getState());
        if ($case === 'both publishers') {
            self::assertSame([], $events->events());
        } else {
            self::assertCount(1, $events->events());
            $failure = $events->events()[0];
            self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $failure);
            self::assertSame('maintainer-42', $failure->getActorId());
            self::assertSame('Agent credential lifecycle failed.', $failure->getErrorMessage());
        }
    }

    public function test_lifecycle_events_round_trip_safe_metadata_and_reject_missing_data(): void
    {
        $id = AgentId::generate();
        $credential = AgentCredentialId::generate();
        $at = new DateTimeImmutable('2026-08-25T12:05:00+00:00');
        $rotated = new AgentCredentialRotated($id, $credential, 3, $at);
        $revoked = new AgentCredentialRevoked($id, $at);
        $failed = new AgentCredentialLifecycleFailed('maintainer-42', 'Agent credential lifecycle failed.');
        foreach ([$rotated, $revoked, $failed] as $event) {
            self::assertSame($event->toArray(), $event::fromArray($event->toArray())->toArray());
            self::assertStringNotContainsString('secret', serialize($event));
            try {
                $event::fromArray([]);
                self::fail('Missing fields must reject.');
            } catch (DomainException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame($id, $rotated->getAgentId());
        self::assertSame($credential, $rotated->getCredentialId());
        self::assertSame(3, $rotated->getCredentialRevision());
        self::assertSame($at, $rotated->getRotatedAt());
        self::assertSame($id, $revoked->getAgentId());
        self::assertSame($at, $revoked->getRevokedAt());
    }

    private function agent(): Agent
    {
        return Agent::provision(
            AgentId::generate(),
            AgentName::fromString('Production deployment'),
            AgentCredentialId::generate(),
            'encrypted:current-secret',
            new DateTimeImmutable('2026-08-25T12:00:00+00:00')
        );
    }

    private function service(
        AgentRepository $agents,
        InMemoryAuditEvidenceRepository $audit,
        InMemoryUnitOfWork $transaction,
        InMemoryEventDispatcher $events
    ): AgentCredentialLifecycleService {
        return new AgentCredentialLifecycleService(
            $agents,
            $audit,
            new FixedClock(new DateTimeImmutable('2026-08-25T12:05:00+00:00')),
            $transaction,
            $events
        );
    }
}
