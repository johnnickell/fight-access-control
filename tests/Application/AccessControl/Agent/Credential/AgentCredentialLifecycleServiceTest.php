<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Credential\AgentCredentialLifecycleService;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentRepository;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialLifecycleFailed;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRevoked;
use Fight\AccessControl\Domain\AccessControl\Agent\Event\AgentCredentialRotated;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Application\Error\Exception\ApplicationException;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use Fight\Test\AccessControl\Application\AccessControl\Event\InMemoryEventDispatcher;
use Fight\Test\AccessControl\Application\AccessControl\Timing\Service\FixedClock;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentCredentialLifecycleService::class)]
#[CoversClass(AgentCredentialRotated::class)]
#[CoversClass(AgentCredentialRevoked::class)]
#[CoversClass(AgentCredentialLifecycleFailed::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentCredentialLifecycleServiceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function failures(): iterable
    {
        foreach (
            ['absent', 'stale', 'repository', 'audit', 'audit both publishers', 'commit', 'publication',
                'both publishers'] as $case
        ) {
            yield $case => [$case];
        }
    }

    #[DataProvider('failures')]
    public function test_revocation_preserves_failure_and_publication_semantics(string $case): void
    {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $agent = $env->agentRepository->all()[0];
        $original = $env->operationRepository->operations[$env->key->toString()];
        $auditEvidenceRepository = new InMemoryAuditEvidenceRepository(
            $env->transaction,
            failAfterSave: str_starts_with($case, 'audit')
        );
        $fault = new RuntimeException('Original failure');
        $agentRepository = $env->agentRepository;
        if (in_array($case, ['absent', 'stale', 'repository'], true)) {
            $agentRepository = $this->createStub(AgentRepository::class);
            $agentRepository->method('getOperationContract')->willReturn($env->agentRepository->getOperationContract());
            $agentRepository->method('getById')->willReturn($case === 'absent' ? null : $agent);
            if ($case === 'repository') {
                $agentRepository->method('replace')->willThrowException($fault);
            } else {
                $agentRepository->method('replace')->willReturn(false);
            }
        }

        $env->transaction->failNextCommit = $case === 'commit';
        $eventDispatcher = new InMemoryEventDispatcher(static function (object $event) use ($case, $fault): void {
            if (
                str_contains($case, 'both publishers')
                || ($case === 'publication' && $event instanceof AgentCredentialRevoked)
            ) {
                throw $fault;
            }
        });
        $service = new AgentCredentialLifecycleService(
            $agentRepository,
            new FixedClock($agent->getCreatedAt()->modify('+5 minutes')),
            $env->transaction,
            $eventDispatcher,
            $auditEvidenceRepository
        );
        $actorId = UserId::generate();
        try {
            $service->revoke($actorId, $agent->getId());
            self::fail('Expected revocation failure.');
        } catch (ApplicationException | LogicException | RuntimeException $failure) {
            if (in_array($case, ['repository', 'publication', 'both publishers'], true)) {
                self::assertSame($fault, $failure);
            } elseif (str_starts_with($case, 'audit')) {
                self::assertSame($auditEvidenceRepository->failure(), $failure);
            }
        }

        $committed = in_array($case, ['publication', 'both publishers'], true);
        self::assertCount((int) $committed, $auditEvidenceRepository->all());
        self::assertSame(
            $committed ? AgentState::REVOKED : AgentState::ACTIVE,
            $env->agentRepository->all()[0]->getState()
        );
        if ($committed) {
            self::assertNull($env->operationRepository->operations[$env->key->toString()]->getMaterial());
        } else {
            self::assertSame($original, $env->operationRepository->operations[$env->key->toString()]);
        }

        if (str_contains($case, 'both publishers')) {
            self::assertSame([], $eventDispatcher->events());
        } else {
            self::assertCount(1, $eventDispatcher->events());
            $failure = $eventDispatcher->events()[0];
            self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $failure);
            self::assertSame($actorId, $failure->getActorId());
            self::assertSame('Agent credential lifecycle failed.', $failure->getErrorMessage());
        }
    }

    /** @return iterable<string, array{string, UserId|AgentId|SystemId|AnonymousId}> */
    public static function auditCompositions(): iterable
    {
        foreach (['omitted', 'null', 'enabled'] as $mode) {
            $actors = [UserId::generate(), AgentId::generate(), SystemId::generate(), AnonymousId::generate()];
            foreach ($actors as $actor) {
                yield $mode.' '.$actor::class => [$mode, $actor];
            }
        }
    }

    #[DataProvider('auditCompositions')]
    public function test_optional_audit_preserves_retirement_and_exact_admitted_facts(
        string $mode,
        UserId|AgentId|SystemId|AnonymousId $actor
    ): void {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $agent = $env->agentRepository->all()[0];
        $beforeOperations = $env->operationRepository->operations;
        $beforeAudit = $env->auditEvidenceRepository->all();
        $at = new DateTimeImmutable('2026-09-28T12:05:00.123456+00:00');
        $args = [$env->agentRepository, new FixedClock($at), $env->transaction, $env->eventDispatcher];
        if ($mode !== 'omitted') {
            $args[] = $mode === 'enabled' ? $env->auditEvidenceRepository : null;
        }

        $service = new AgentCredentialLifecycleService(...$args);
        $service->revoke($actor, $agent->getId());

        $revoked = $env->agentRepository->getById($agent->getId());
        self::assertSame(AgentState::REVOKED, $revoked->getState());
        self::assertSame($at, $revoked->getUpdatedAt());
        $operation = $env->operationRepository->operations[$env->key->toString()];
        self::assertNull($operation->getMaterial());
        self::assertSame($beforeOperations[$env->key->toString()]->getIssuance(), $operation->getIssuance());
        self::assertSame(
            $beforeOperations[$env->key->toString()]->getStateRevision() + 1,
            $operation->getStateRevision()
        );
        $records = $env->auditEvidenceRepository->all();
        self::assertCount(count($beforeAudit) + (int) ($mode === 'enabled'), $records);
        if ($mode === 'enabled') {
            $record = $records[array_key_last($records)];
            self::assertSame($actor, $record->actorId());
            $payload = $record->toArray();
            self::assertSame(['kind' => 'agent', 'id' => $agent->getId()->toString()], $payload['subject']);
            self::assertSame('agent.credential_revoked', $payload['action']);
            self::assertSame($at->format('Y-m-d\TH:i:s.uP'), $payload['occurred_at']);
            self::assertNull($payload['correlation']);
            self::assertSame([
                'before'             => [
                    'credential_id'       => $agent->getCredentialId()->toString(),
                    'credential_revision' => $agent->getCredentialRevision(),
                    'state'               => AgentState::ACTIVE->value
                ],
                'after'              => [
                    'credential_id'       => $agent->getCredentialId()->toString(),
                    'credential_revision' => $agent->getCredentialRevision(),
                    'state'               => AgentState::REVOKED->value
                ],
                'delivery_id'        => null,
                'operation_revision' => null
            ], $payload['facts']);
            self::assertSame($payload, AuditEvidence::fromArray($payload)->toArray());
        } else {
            self::assertSame($beforeAudit, $records);
        }

        try {
            $service->revoke($actor, $agent->getId());
            self::fail('Already revoked authority is a rejection, not an audited no-op.');
        } catch (ApplicationException) {
            self::assertSame($records, $env->auditEvidenceRepository->all());
            self::assertSame($operation, $env->operationRepository->operations[$env->key->toString()]);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function uncertainOutcomes(): iterable
    {
        foreach ([false, true] as $enabled) {
            foreach ([false, true] as $committed) {
                yield ($enabled ? 'enabled' : 'disabled').' '.($committed ? 'committed' : 'rolled back') => [
                    $enabled, $committed
                ];
            }
        }
    }

    #[DataProvider('uncertainOutcomes')]
    public function test_lost_acknowledgement_rethrows_exact_primary_without_guessing_rollback(
        bool $enabled,
        bool $committed
    ): void {
        $env = new ProvisioningEnvironment();
        $env->bus()->commandBus->execute(new ProvisionAgent($env->key, $env->request));
        $agent = $env->agentRepository->all()[0];
        $beforeOperations = $env->operationRepository->operations;
        $beforeAudit = $env->auditEvidenceRepository->all();
        $fault = new RuntimeException('Commit acknowledgement unavailable.');
        $fail = static function () use ($fault): void {
            throw $fault;
        };
        if ($committed) {
            $env->transaction->afterCommit = $fail;
        } else {
            $env->transaction->beforeCommit = $fail;
        }

        $attempted = [];
        $dispatcher = new InMemoryEventDispatcher(static function (object $event) use (&$attempted): void {
            $attempted[] = $event;

            throw new RuntimeException('Secondary publication unavailable.');
        });
        try {
            new AgentCredentialLifecycleService(
                $env->agentRepository,
                new FixedClock($agent->getCreatedAt()),
                $env->transaction,
                $dispatcher,
                $enabled ? $env->auditEvidenceRepository : null
            )->revoke(UserId::generate(), $agent->getId());
            self::fail('An uncertain acknowledgement must not become success.');
        } catch (RuntimeException $runtimeException) {
            self::assertSame($fault, $runtimeException);
        }

        self::assertCount(1, $attempted);
        self::assertInstanceOf(AgentCredentialLifecycleFailed::class, $attempted[0]);
        self::assertSame(
            $committed ? AgentState::REVOKED : AgentState::ACTIVE,
            $env->agentRepository->getById($agent->getId())->getState()
        );
        self::assertCount(count($beforeAudit) + (int) ($enabled && $committed), $env->auditEvidenceRepository->all());
        if ($committed) {
            self::assertNull($env->operationRepository->operations[$env->key->toString()]->getMaterial());
        } else {
            self::assertSame($beforeOperations, $env->operationRepository->operations);
            self::assertSame($beforeAudit, $env->auditEvidenceRepository->all());
        }
    }

    public function test_lifecycle_events_round_trip_safe_metadata_and_reject_missing_data(): void
    {
        $id = AgentId::generate();
        $credential = AgentCredentialId::generate();
        $at = new DateTimeImmutable('2026-08-25T12:05:00+00:00');
        $rotated = new AgentCredentialRotated($id, $credential, 3, $at);
        $revoked = new AgentCredentialRevoked($id, $at);
        $failed = new AgentCredentialLifecycleFailed(UserId::generate(), 'Agent credential lifecycle failed.');
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
}
