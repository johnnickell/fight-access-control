<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentMaintenanceOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Repository\{
    InMemoryAgentOperationContract
};
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentDeliveryMaintenanceService::class)]
#[CoversClass(AuditAgentMaintenanceOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentMaintenanceAuditTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function outcomes(): iterable
    {
        foreach (['rewrap', 'key retired', 'corrupt', 'expire', 'rewrap expiry', 'cleanup'] as $path) {
            foreach (['system', 'user', 'agent'] as $actor) {
                yield $path.' '.$actor => [$path, $actor];
            }
        }
    }

    #[DataProvider('outcomes')]
    public function test_real_outcome_records_exact_relationships_revisions_time_and_origin(
        string $path,
        string $actor
    ): void {
        $env = $this->environment($path);
        $env->actorId = match ($actor) {
            'system' => SystemId::generate(),
            'user' => UserId::generate(),
            default => AgentId::generate()
        };
        $before = $env->delivery->operation();
        $occurredAt = $env->delivery->clock->now();
        $recordsBefore = count($env->delivery->provisioning->auditEvidenceRepository->all());

        self::assertSame($this->expected($path), $this->invoke($env, $path));
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        self::assertCount($recordsBefore + 1, $records);
        $evidence = $records[array_key_last($records)];
        $after = $env->delivery->operation();
        self::assertSame($env->actorId, $evidence->actorId());
        self::assertSame($occurredAt, $evidence->getOccurredAt());
        self::assertSame($before->getIssuance()->getAgentId(), $evidence->getSubject()->getId());
        self::assertSame($before->getIssuance()->getKey(), $evidence->getCorrelation()?->getOperationKey());
        self::assertSame([
            'credential_id'      => $before->getIssuance()->getCredentialId()->toString(),
            'delivery_id'        => $before->getIssuance()->getDeliveryId()->toString(),
            'revision_before'    => $before->getStateRevision(),
            'revision_after'     => $before->getStateRevision() + 1,
            'key_version_before' => $before->getMaterial()?->getKeyVersion(),
            'key_version_after'  => $after->getMaterial()?->getKeyVersion(),
            'failure'            => $after->getDeliveryFailure()?->value
        ], $evidence->getFacts()->toArray());
        self::assertSame($evidence->toArray(), AuditEvidence::fromArray($evidence->toArray())->toArray());
        self::assertSame($before->getIssuance(), $after->getIssuance());
        self::assertSame($before->getCredentialDisposition(), $after->getCredentialDisposition());
        self::assertSame($before->getReceipt(), $after->getReceipt());
        self::assertSame($before->getAttempt(), $after->getAttempt());
        self::assertNotSame($records[0]->getId()->toString(), $evidence->getId()->toString());
        self::assertStringNotContainsString(
            'original-test-secret',
            json_encode($evidence->toArray(), JSON_THROW_ON_ERROR)
        );

        $env->actorId = null;
        self::assertContains($this->invoke($env, $path), [
            AgentMaintenanceResult::UNCHANGED, AgentMaintenanceResult::CLEANED
        ]);
        self::assertCount($recordsBefore + 1, $env->delivery->provisioning->auditEvidenceRepository->all());
    }

    /** @return iterable<string, array{string}> */
    public static function paths(): iterable
    {
        foreach (['rewrap', 'key retired', 'corrupt', 'expire', 'rewrap expiry', 'cleanup'] as $path) {
            yield $path => [$path];
        }
    }

    #[DataProvider('paths')]
    public function test_missing_provenance_rejects_before_enabled_mutation_or_external_effect(string $path): void
    {
        $env = $this->environment($path);
        $env->actorId = null;

        $before = $env->delivery->operation();
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();

        self::assertSame(AgentMaintenanceResult::REJECTED, $this->invoke($env, $path));
        self::assertSame($before, $env->delivery->operation());
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame(0, $env->rewraps);
        self::assertSame(0, $env->delivery->sink->cleanups);
    }

    #[DataProvider('paths')]
    public function test_audit_failure_rolls_back_state_and_evidence_but_not_external_erasure(string $path): void
    {
        $env = $this->environment($path);
        $before = $env->delivery->operation();
        $audit = new InMemoryAuditEvidenceRepository($env->delivery->provisioning->transaction, true);
        $service = $this->service($env, $audit);

        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $this->invoke($env, $path, $service));
        self::assertSame($before, $env->delivery->operation());
        self::assertSame([], $audit->all());
        self::assertNotNull($audit->failure());
        self::assertSame($path === 'cleanup' ? 1 : 0, $env->delivery->sink->cleanups);
        if ($path === 'cleanup') {
            self::assertFalse($env->delivery->operation()->isSinkCleaned());
            self::assertNull($env->delivery->sink->stagedBytes($env->delivery->issuance));
            try {
                $env->delivery->sink->stage(new AgentCredentialInvocation(
                    $env->delivery->issuance,
                    'original-test-secret'
                ));
                self::fail('Audit rollback cannot remove the sink non-recreation tombstone.');
            } catch (AgentDeliveryFailedException) {
                self::assertNull($env->delivery->sink->stagedBytes($env->delivery->issuance));
            }
        }

        self::assertSame($this->expected($path), $this->invoke($env, $path));
        self::assertSame($before->getIssuance(), $env->delivery->operation()->getIssuance());
        self::assertSame(1, $env->delivery->provisioning->generations);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function uncertain(): iterable
    {
        foreach (['rewrap', 'key retired', 'expire', 'rewrap expiry', 'cleanup', 'admission'] as $path) {
            yield $path.' persisted' => [$path, true];
            yield $path.' rolled back' => [$path, false];
        }
    }

    #[DataProvider('uncertain')]
    public function test_uncertain_outcome_preserves_exact_state_evidence_pair_and_same_tuple_recovery(
        string $path,
        bool $persist
    ): void {
        $env = $this->environment($path === 'admission' ? 'cleanup' : $path);
        $recordsBefore = count($env->delivery->provisioning->auditEvidenceRepository->all());
        $env->delivery->transaction->uncertainAt = $path === 'cleanup' ? 2 : 1;
        $env->delivery->transaction->persistUncertain = $persist;

        $call = $path === 'admission' ? 'cleanup' : $path;

        self::assertSame(AgentMaintenanceResult::INDETERMINATE, $this->invoke($env, $call));
        self::assertCount(
            $recordsBefore + ($persist && $path !== 'admission' ? 1 : 0),
            $env->delivery->provisioning->auditEvidenceRepository->all()
        );
        self::assertSame($path === 'cleanup' ? 1 : 0, $env->delivery->sink->cleanups);
        $env->delivery->transaction->uncertainAt = null;
        self::assertContains($this->invoke($env, $call), [
            $this->expected($call), AgentMaintenanceResult::UNCHANGED, AgentMaintenanceResult::CLEANED
        ]);
        self::assertCount($recordsBefore + 1, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame(1, $env->delivery->provisioning->generations);
    }

    public function test_lost_sink_response_has_no_evidence_until_same_tuple_acknowledgement(): void
    {
        $env = $this->environment('cleanup');
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        $env->delivery->sink->afterCleanup = static function (): void {
            throw new RuntimeException('Untrusted cleanup diagnostic.');
        };

        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $env->cleanup());
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertFalse($env->delivery->operation()->isSinkCleaned());
        $env->delivery->sink->afterCleanup = null;
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertCount(count($records) + 1, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame(2, $env->delivery->sink->cleanups);
    }

    public function test_enabled_no_change_and_transient_work_does_not_need_actor_or_create_evidence(): void
    {
        $env = $this->environment('rewrap');
        $env->actorId = null;

        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->rewrapOriginal('test-key-v1'));
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->expire());
        self::assertSame(AgentMaintenanceResult::UNCHANGED, $env->cleanup());
        $env->actorId = SystemId::generate();
        $env->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(AgentMaintenanceResult::RETRYABLE, $env->rewrapOriginal());
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
    }

    #[DataProvider('paths')]
    public function test_slow_audit_expiring_authority_rolls_back_staged_outcome(string $path): void
    {
        $env = $this->environment($path);
        $before = $env->delivery->operation();
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        $env->expiresAt = $env->delivery->clock->now()->modify('+1 second');
        $audit = new readonly class ($env) implements AuditEvidenceRepository {
            public function __construct(private MaintenanceEnvironment $env)
            {
            }

            public function add(AuditEvidence $evidence): void
            {
                $this->env->delivery->provisioning->auditEvidenceRepository->add($evidence);
                $this->env->delivery->clock->advance(1);
            }
        };

        self::assertSame(AgentMaintenanceResult::REJECTED, $this->invoke($env, $path, $this->service($env, $audit)));
        self::assertSame($before, $env->delivery->operation());
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame($path === 'cleanup' ? 1 : 0, $env->delivery->sink->cleanups);
    }

    /** @return iterable<string, array{string}> */
    public static function races(): iterable
    {
        $races = ['key closure', 'deadline', 'retention', 'cleanup epoch', 'cleanup revision', 'cleanup denial'];
        foreach ($races as $race) {
            yield $race => [$race];
        }
    }

    #[DataProvider('races')]
    public function test_fenced_losing_writer_cannot_commit_audit_or_restore_material(string $race): void
    {
        $cleanup = str_starts_with($race, 'cleanup');
        $env = $this->environment($cleanup ? 'cleanup' : 'rewrap');
        $before = $env->delivery->operation();
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        $change = static function () use ($env, $race): void {
            if ($race === 'key closure') {
                $env->delivery->provisioning->operationRepository->closedKeyVersions['test-key-v2'] = true;
            } elseif ($race === 'deadline') {
                $env->delivery->clock->advance(30 * 86400);
            } elseif ($race === 'retention') {
                $env->delivery->clock->advance(86400);
            } elseif ($race === 'cleanup epoch') {
                ++$env->epoch;
            } elseif ($race === 'cleanup denial') {
                $env->permitted = false;
            } else {
                $env->delivery->provisioning->operationRepository->operations[
                    $env->delivery->issuance->getKey()->toString()
                ] = $env->delivery->operation()->retireMaterial();
            }
        };
        if ($cleanup) {
            $env->delivery->sink->afterCleanup = $change;
        } else {
            $env->afterRewrap = $change;
        }

        self::assertSame(AgentMaintenanceResult::REJECTED, $this->invoke($env, $cleanup ? 'cleanup' : 'rewrap'));
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertFalse($env->delivery->operation()->isSinkCleaned());
        self::assertSame($before->getMaterial(), $env->delivery->operation()->getMaterial());
        self::assertSame($cleanup ? 1 : 0, $env->delivery->sink->cleanups);
    }

    public function test_audit_storage_fault_cannot_supply_maintenance_authorization_intent(): void
    {
        $env = $this->environment('rewrap');
        $before = $env->delivery->operation();
        $audit = new readonly class implements AuditEvidenceRepository {
            public function add(AuditEvidence $evidence): void
            {
                throw new AgentOperationRejectedException(AgentOperationFailure::UNAUTHORIZED);
            }
        };

        self::assertSame(
            AgentMaintenanceResult::UNAVAILABLE,
            $this->invoke($env, 'rewrap', $this->service($env, $audit))
        );
        self::assertSame($before, $env->delivery->operation());
    }

    #[DataProvider('paths')]
    public function test_missing_restoration_readiness_blocks_state_and_evidence(string $path): void
    {
        $env = $this->environment($path);
        $before = $env->delivery->operation();
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        $contract = $env->delivery->provisioning->operationRepository->contract;
        $contract->reconciledGeneration = null;

        self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $this->invoke($env, $path));
        self::assertSame($before, $env->delivery->operation());
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame(0, $env->rewraps);
        self::assertSame(0, $env->delivery->sink->cleanups);

        $contract->reconciledGeneration = $contract->generation;
        self::assertSame($this->expected($path), $this->invoke($env, $path));
        self::assertCount(count($records) + 1, $env->delivery->provisioning->auditEvidenceRepository->all());
    }

    /** @return iterable<string, array{string}> */
    public static function cohortBoundaries(): iterable
    {
        yield 'restore before external effect' => ['admission'];
        yield 'restore after erasure' => ['sink'];
        yield 'advanced cohort after erasure' => ['generation'];
    }

    #[DataProvider('cohortBoundaries')]
    public function test_cleanup_rechecks_restoration_and_cohort_before_acknowledgement(string $boundary): void
    {
        $env = $this->environment('cleanup');
        $before = $env->delivery->operation();
        $records = $env->delivery->provisioning->auditEvidenceRepository->all();
        $contract = $env->delivery->provisioning->operationRepository->contract;
        $change = static function () use ($contract, $boundary): void {
            if ($boundary === 'generation') {
                $contract->switchTo(InMemoryAgentOperationContract::compatible(2));
                $contract->generation = 2;
                $contract->reconciledGeneration = 2;
            } else {
                $contract->reconciledGeneration = null;
            }
        };
        if ($boundary === 'admission') {
            $env->delivery->transaction->afterCommit = $change;
        } else {
            $env->delivery->sink->afterCleanup = $change;
        }

        self::assertSame(
            $boundary === 'generation' ? AgentMaintenanceResult::REJECTED : AgentMaintenanceResult::UNAVAILABLE,
            $env->cleanup()
        );
        self::assertSame($before, $env->delivery->operation());
        self::assertSame($records, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame($boundary === 'admission' ? 0 : 1, $env->delivery->sink->cleanups);
        $env->delivery->transaction->afterCommit = null;
        $env->delivery->sink->afterCleanup = null;

        $contract->reconciledGeneration = $contract->generation;
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertCount(count($records) + 1, $env->delivery->provisioning->auditEvidenceRepository->all());
        self::assertSame($before->getIssuance(), $env->delivery->operation()->getIssuance());
    }

    private function environment(string $path): MaintenanceEnvironment
    {
        $env = new MaintenanceEnvironment();
        $env->auditEnabled = true;
        $env->actorId = SystemId::generate();
        if ($path === 'cleanup') {
            $env->delivery->revoke();
            $env->delivery->clock->advance(172800);
        } elseif (in_array($path, ['expire', 'rewrap expiry'], true)) {
            $env->delivery->clock->advance(86400);
        } elseif ($path === 'key retired') {
            $env->failure = AgentDeliveryFailure::KEY_RETIRED;
        } elseif ($path === 'corrupt') {
            $env->failure = AgentDeliveryFailure::CORRUPT_MATERIAL;
        }

        return $env;
    }

    private function service(
        MaintenanceEnvironment $env,
        AuditEvidenceRepository $audit
    ): AgentDeliveryMaintenanceService {
        return new AgentDeliveryMaintenanceService(
            $env->delivery->provisioning->operationRepository,
            $env,
            $env,
            $env->delivery->sink,
            $env->delivery->clock,
            $env->delivery->transaction,
            auditEvidenceRepository: $audit
        );
    }

    private function invoke(
        MaintenanceEnvironment $env,
        string $path,
        ?AgentDeliveryMaintenanceService $service = null
    ): AgentMaintenanceResult {
        $service ??= $env->service();
        $issuance = $env->delivery->issuance;
        $key = $issuance->getKey();
        $destination = $issuance->getDestination();
        $delivery = $issuance->getDeliveryId();

        return match ($path) {
            'expire' => $service->expire($key, $destination, $delivery, $env->actorId),
            'cleanup' => $service->cleanup($key, $destination, $delivery, $env->actorId),
            default => $service->rewrap(
                $key,
                $destination,
                $delivery,
                new AgentDeliveryKeyVersion('test-key-v2'),
                $env->actorId
            )
        };
    }

    private function expected(string $path): AgentMaintenanceResult
    {
        return match ($path) {
            'rewrap' => AgentMaintenanceResult::REWRAPPED,
            'key retired', 'corrupt' => AgentMaintenanceResult::TERMINAL,
            'cleanup' => AgentMaintenanceResult::CLEANED,
            default => AgentMaintenanceResult::EXPIRED
        };
    }
}
