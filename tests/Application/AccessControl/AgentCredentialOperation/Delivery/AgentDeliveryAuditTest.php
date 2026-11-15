<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery;

use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\AgentCredentialOperation\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\ProvisionAgent;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAction;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditActorKind;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditAgentDeliveryOutcome;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidence;
use Fight\AccessControl\Domain\AccessControl\Audit\AuditEvidenceRepository;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Adapter\Messaging\Query\Routing\InMemoryQueryRouter;
use Fight\Common\Adapter\Messaging\Query\RoutingQueryBus;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Credential\ProvisioningEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Audit\Repository\InMemoryAuditEvidenceRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(AuditAgentDeliveryOutcome::class)]
#[CoversClass(AuditEvidence::class)]
final class AgentDeliveryAuditTest extends TestCase
{
    /** @return iterable<string, array{?string, bool}> */
    public static function compositions(): iterable
    {
        foreach (['user', 'agent', 'system', 'anonymous', null] as $kind) {
            foreach ([false, true] as $enabled) {
                yield ($kind ?? 'missing').' '.(int) $enabled => [$kind, $enabled];
            }
        }
    }

    #[DataProvider('compositions')]
    public function test_restarted_delegated_scheduler_preserves_typed_origin_and_optional_audit(
        ?string $kind,
        bool $enabled
    ): void {
        $provisioning = new ProvisioningEnvironment();
        $origin = match ($kind) {
            'user' => UserId::generate(),
            'agent' => AgentId::generate(),
            'system' => SystemId::generate(),
            'anonymous' => AnonymousId::generate(),
            null => null
        };
        $provisioning->operationAuthorization->originatingActorId = $origin;
        $issuance = $provisioning->bus(auditEnabled: false)->dispatchAndRead(new ProvisionAgent(
            $provisioning->key,
            $provisioning->request
        ))->getIssuance();
        $env = new DeliveryEnvironment($provisioning, $issuance);
        $this->hydrateOrigin($env);
        $provisioning->operationAuthorization->actor = 'delegated-worker';
        $delegation = 'delegated-worker:'.$provisioning->key->getScope()->toString();
        $provisioning->operationAuthorization->readDelegations[$delegation] = true;
        $provisioning->operationAuthorization->originatingActorId = AgentId::generate();
        $repository = $enabled ? $provisioning->auditEvidenceRepository : null;
        $scheduler = $this->scheduler($env, $repository);
        $before = [$env->operation(), $provisioning->auditEvidenceRepository->all(), $env->transaction->commits];
        $scheduler->nextRunAt();
        self::assertSame($before, [$env->operation(), $provisioning->auditEvidenceRepository->all(),
            $env->transaction->commits]);
        $result = $scheduler->recover($provisioning->key->getScope(), $issuance->getDestination());
        $expected = AgentDeliveryResult::DELIVERED;
        if ($enabled && $origin === null) {
            $expected = AgentDeliveryResult::UNAVAILABLE;
        }

        self::assertSame([$issuance->getDeliveryId()->toString() => $expected], $result);
        self::assertSame($enabled && $origin === null ? 0 : 1, $env->sink->calls);
        self::assertCount($enabled && $origin !== null ? 1 : 0, $provisioning->auditEvidenceRepository->all());
        if ($enabled && $origin !== null) {
            $evidence = $provisioning->auditEvidenceRepository->all()[0];
            self::assertEquals($origin, $evidence->actorId());
            self::assertSame($kind, $evidence->toArray()['actor']['kind']);
            self::assertSame($issuance->getKey()->toArray(), $evidence->getCorrelation()->getOperationKey()->toArray());
            self::assertNull($evidence->getCorrelation()->getMessageId());
            self::assertEquals($issuance->getAgentId(), $evidence->getSubject()->getId());
            self::assertSame($this->facts($env, 2, 3, 'pending', 'delivered'), $evidence->getFacts()->toArray());
            self::assertSame([], $this->scheduler($env, $repository)->recover(
                $provisioning->key->getScope(),
                $issuance->getDestination()
            ));
        }

        self::assertSame(1, $provisioning->generations);
    }

    public function test_outcome_time_is_the_transition_time_not_issuance_claim_or_admission_time(): void
    {
        $env = new DeliveryEnvironment();
        $env->transaction->afterCommit = static function (int $stage) use ($env): void {
            if ($stage < 3) {
                self::assertCount(1, $env->provisioning->auditEvidenceRepository->all());
                $env->clock->advance(1);
            }
        };
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env));
        $evidence = array_last($env->provisioning->auditEvidenceRepository->all());
        self::assertSame($env->clock->now(), $evidence->getOccurredAt());
        self::assertNotEquals($env->issuance->getIssuedAt(), $evidence->getOccurredAt());
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        $env->provisioning->operationAuthorization->originatingActorId = null;
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env));
        self::assertCount(2, $env->provisioning->auditEvidenceRepository->all());
        $env->sink->forgetMaterial($env->issuance);
        self::assertSame(AgentDeliveryResult::RECONCILIATION_REQUIRED, $this->deliver($env));
        self::assertCount(2, $env->provisioning->auditEvidenceRepository->all());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function uncertainCommits(): iterable
    {
        foreach ([1 => 'claim', 2 => 'admission', 3 => 'outcome'] as $stage => $name) {
            yield $name.' committed' => [$stage, true];
            yield $name.' rolled back' => [$stage, false];
        }
    }

    #[DataProvider('uncertainCommits')]
    public function test_persisted_state_and_evidence_agree_under_both_uncertain_commit_outcomes(
        int $stage,
        bool $persist
    ): void {
        $env = new DeliveryEnvironment();
        $env->transaction->uncertainAt = $stage;
        $env->transaction->persistUncertain = $persist;
        self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($env));
        self::assertSame($persist ? $stage : $stage - 1, $env->operation()->getStateRevision());
        self::assertCount($stage === 3 && $persist ? 2 : 1, $env->provisioning->auditEvidenceRepository->all());
        self::assertSame($stage === 3 ? 1 : 0, $env->sink->calls);
        $env->transaction->uncertainAt = null;
        $env->clock->advance(61);
        $this->reopenEvidence($env);
        $this->scheduler($env, $env->provisioning->auditEvidenceRepository)->recover(
            $env->issuance->getKey()->getScope(),
            $env->issuance->getDestination()
        );
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getDeliveryDisposition());
        self::assertCount(2, $env->provisioning->auditEvidenceRepository->all());
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->provisioning->generations);
        self::assertSame($env->sink->receiptFor($env->issuance), $env->operation()->getReceipt());
    }

    public function test_audit_failure_after_send_preserves_receipt_for_single_recovery(): void
    {
        $env = new DeliveryEnvironment();
        $failedAudit = new InMemoryAuditEvidenceRepository($env->provisioning->transaction, failAfterSave: true);
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, $failedAudit));
        self::assertSame([], $failedAudit->all());
        self::assertSame(2, $env->operation()->getStateRevision());
        self::assertNotNull($env->operation()->getMaterial());
        self::assertNull($env->operation()->getReceipt());
        self::assertNotNull($env->sink->receiptFor($env->issuance));
        self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
        $this->reopenEvidence($env);
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env));
        self::assertCount(2, $env->provisioning->auditEvidenceRepository->all());
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->decipher->calls);
    }

    /** @return iterable<string, array{string}> */
    public static function outcomes(): iterable
    {
        foreach (['retryable', 'terminal', 'expiry', 'exhaustion'] as $outcome) {
            yield $outcome => [$outcome];
        }
    }

    #[DataProvider('outcomes')]
    public function test_committed_failures_and_claim_terminalization_record_actual_transition(string $outcome): void
    {
        $env = new DeliveryEnvironment();
        if ($outcome === 'expiry') {
            $env->clock->advance(86400);
            $env->deliveryAuthorization->expiresAt = $env->clock->now()->modify('+1 day');
        } elseif ($outcome === 'exhaustion') {
            $env->sink->beforeStage = static function (): void {
                throw new RuntimeException('Synthetic private provider fault.');
            };
            self::assertSame(AgentDeliveryResult::RETRYABLE, $this->deliver($env, policy: new AgentDeliveryPolicy(
                maximumAttempts: 1
            )));
            $env->clock->advance(61);
        } elseif ($outcome === 'terminal') {
            $env->sink->supported = false;
        } else {
            $env->sink->beforeStage = static function (): void {
                throw new RuntimeException('Synthetic private provider fault.');
            };
        }

        $revision = $env->operation()->getStateRevision();
        $result = $this->deliver($env);
        $expected = match ($outcome) {
            'expiry' => AgentDeliveryResult::EXPIRED,
            'retryable' => AgentDeliveryResult::RETRYABLE,
            default => AgentDeliveryResult::TERMINAL
        };
        self::assertSame($expected, $result);
        $evidence = array_last($env->provisioning->auditEvidenceRepository->all());
        self::assertSame(match ($outcome) {
            'expiry' => AuditAction::AGENT_DELIVERY_EXPIRED,
            'exhaustion' => AuditAction::AGENT_DELIVERY_EXHAUSTED,
            default => AuditAction::AGENT_DELIVERY_FAILED
        }, $evidence->action());
        self::assertSame($env->clock->now(), $evidence->getOccurredAt());
        self::assertSame($env->operation()->getStateRevision(), $evidence->getFacts()->toArray()['after_revision']);
        self::assertSame(
            $revision + (in_array($outcome, ['expiry', 'exhaustion'], true) ? 0 : 2),
            $evidence->getFacts()->toArray()['before_revision']
        );
        self::assertEquals($evidence, AuditEvidence::fromArray($evidence->toArray()));
        $encoded = json_encode($evidence->toArray(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Synthetic private', $encoded);
        self::assertStringNotContainsString('original-test-secret', $encoded);
    }

    public function test_claim_expiry_audit_failure_before_sink_retains_original_pending_material(): void
    {
        $env = new DeliveryEnvironment();
        $before = $env->operation();
        $env->clock->advance(86400);
        $env->deliveryAuthorization->expiresAt = $env->clock->now()->modify('+1 day');

        $audit = new InMemoryAuditEvidenceRepository($env->provisioning->transaction, failAfterSave: true);
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, $audit));
        self::assertSame($before, $env->operation());
        self::assertSame([], $audit->all());
        self::assertSame(0, $env->sink->calls);
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(AgentDeliveryResult::EXPIRED, $this->deliver($env));
    }

    public function test_no_change_receipt_only_poll_needs_no_new_attribution_and_adds_no_evidence(): void
    {
        $provisioning = new ProvisioningEnvironment();
        $provisioning->operationAuthorization->originatingActorId = null;

        $issuance = $provisioning->bus(auditEnabled: false)->dispatchAndRead(new ProvisionAgent(
            $provisioning->key,
            $provisioning->request
        ))->getIssuance();
        $env = new DeliveryEnvironment($provisioning, $issuance);
        $env->transaction->uncertainAt = 2;
        $env->transaction->persistUncertain = true;
        self::assertSame(AgentDeliveryResult::INDETERMINATE, $env->deliver());
        $env->transaction->uncertainAt = null;
        self::assertSame(AgentDeliveryResult::DEFERRED, $this->deliver($env));
        self::assertSame([], $env->provisioning->auditEvidenceRepository->all());
        self::assertSame(0, $env->sink->calls);
        self::assertSame(2, $env->operation()->getStateRevision());
    }

    public function test_current_worker_denial_and_stale_late_acknowledgement_never_commit_delivery_evidence(): void
    {
        foreach (['denied', 'revoked', 'rewrapped'] as $case) {
            $env = new DeliveryEnvironment();
            if ($case === 'denied') {
                $env->deliveryAuthorization->permitted = false;
            } elseif ($case === 'revoked') {
                $env->sink->afterStage = static function () use ($env): void {
                    $env->revoke();
                };
            } else {
                $env->sink->afterStage = static function () use ($env): void {
                    $old = $env->operation();
                    $env->provisioning->transaction->commitTransactional(static function () use ($env, $old): void {
                        $env->provisioning->operationAuthorization->authorize(
                            $env->issuance->getKey()->getScope(),
                            $env->issuance->getDestination()
                        );
                        $env->provisioning->operationRepository->replaceMaintenance(
                            $old,
                            $old->rewrapMaterial($old->getMaterial(), $env->clock->now())
                        );
                    });
                };
            }

            self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($env));
            $delivery = array_filter(
                $env->provisioning->auditEvidenceRepository->all(),
                static fn (AuditEvidence $evidence): bool => $evidence->getFacts() instanceof AuditAgentDeliveryOutcome
            );
            self::assertSame([], $delivery);
            self::assertNotSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getDeliveryDisposition());
            self::assertSame($case === 'denied' ? 0 : 1, $env->sink->calls);
        }
    }

    public function test_missing_origin_blocks_expiry_but_does_not_backfill_recorded_success(): void
    {
        $provisioning = new ProvisioningEnvironment();
        $provisioning->operationAuthorization->originatingActorId = null;

        $issuance = $provisioning->bus(auditEnabled: false)->dispatchAndRead(new ProvisionAgent(
            $provisioning->key,
            $provisioning->request
        ))->getIssuance();
        $env = new DeliveryEnvironment($provisioning, $issuance);
        $before = $env->operation();
        $env->clock->advance(86400);
        $env->deliveryAuthorization->expiresAt = $env->clock->now()->modify('+1 day');
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env));
        self::assertSame($before, $env->operation());
        self::assertSame(0, $env->sink->calls);
        self::assertSame([], $provisioning->auditEvidenceRepository->all());
        $env->clock->time = $issuance->getIssuedAt();
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env));
        self::assertSame([], $provisioning->auditEvidenceRepository->all());
        self::assertSame(1, $env->sink->calls);
    }

    public function test_audit_provider_refusal_is_unavailable_not_business_rejection_or_contention(): void
    {
        foreach (AgentOperationFailure::cases() as $reason) {
            $env = new DeliveryEnvironment();
            $repository = new readonly class ($reason) implements AuditEvidenceRepository {
                public function __construct(private AgentOperationFailure $reason)
                {
                }

                public function add(AuditEvidence $evidence): void
                {
                    throw new AgentOperationRejectedException($this->reason);
                }
            };
            self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, $repository));
            self::assertSame(2, $env->operation()->getStateRevision());
            self::assertNotNull($env->sink->receiptFor($env->issuance));
            self::assertCount(1, $env->provisioning->auditEvidenceRepository->all());
        }
    }

    public function test_failed_audit_restores_admission_not_the_external_attempt(): void
    {
        $env = new DeliveryEnvironment();
        $env->sink->beforeStage = static function (): void {
            throw new RuntimeException('Synthetic protected failure.');
        };
        $audit = new InMemoryAuditEvidenceRepository($env->provisioning->transaction, failAfterSave: true);
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, $audit));
        self::assertSame(AgentDeliveryDisposition::PENDING, $env->operation()->getDeliveryDisposition());
        self::assertSame(2, $env->operation()->getStateRevision());
        self::assertSame([], $audit->all());
        self::assertSame(1, $env->sink->calls);
        self::assertNull($env->sink->receiptFor($env->issuance));
    }

    /** @return iterable<string, array{bool}> */
    public static function uncertainExpiry(): iterable
    {
        yield 'committed' => [true];
        yield 'rolled back' => [false];
    }

    #[DataProvider('uncertainExpiry')]
    public function test_claim_expiry_uncertainty_preserves_atomic_state_and_evidence(bool $persist): void
    {
        $env = new DeliveryEnvironment();
        $env->clock->advance(86400);
        $env->deliveryAuthorization->expiresAt = $env->clock->now()->modify('+1 day');
        $env->transaction->uncertainAt = 1;
        $env->transaction->persistUncertain = $persist;
        self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($env));
        self::assertSame(
            $persist ? AgentDeliveryDisposition::EXPIRED : AgentDeliveryDisposition::PENDING,
            $env->operation()->getDeliveryDisposition()
        );
        self::assertCount($persist ? 2 : 1, $env->provisioning->auditEvidenceRepository->all());
        self::assertSame(0, $env->sink->calls);
        $env->transaction->uncertainAt = null;
        self::assertSame(AgentDeliveryResult::EXPIRED, $this->deliver($env));
        self::assertCount(2, $env->provisioning->auditEvidenceRepository->all());
    }

    private function deliver(
        DeliveryEnvironment $env,
        ?AuditEvidenceRepository $audit = null,
        ?AgentDeliveryPolicy $policy = null
    ): AgentDeliveryResult {
        $service = $env->service(
            $policy,
            new ReceiptLookupSink($env),
            $audit ?? $env->provisioning->auditEvidenceRepository
        );

        return $service->deliver(
            $env->issuance->getKey(),
            $env->issuance->getDestination(),
            $env->issuance->getDeliveryId()
        );
    }

    private function scheduler(
        DeliveryEnvironment $env,
        ?AuditEvidenceRepository $audit
    ): AgentDeliveryRecoveryService {
        $handler = new ListDueAgentDeliveriesHandler(
            $env->provisioning->operationRepository,
            $env->deliveryAuthorization,
            $env->clock
        );

        $router = new InMemoryQueryRouter();
        $router->registerHandler($handler::queryRegistration(), $handler);

        return new AgentDeliveryRecoveryService(
            new RoutingQueryBus($router),
            $env->service(sink: new ReceiptLookupSink($env), auditEvidenceRepository: $audit),
            $env->clock
        );
    }

    private function hydrateOrigin(DeliveryEnvironment $env): void
    {
        $original = $env->operation();
        $origin = $original->getOriginatingActorId();
        $kind = $origin === null ? null : AuditActorKind::forId($origin)->value;
        $id = $origin?->toString();
        $key = $env->issuance->getKey()->toString();
        $env->provisioning->operationRepository->operations[$key] = new AgentCredentialOperation(
            $original->getCanonicalVersion(),
            $original->getCanonicalRequest(),
            AgentIssuance::fromArray($original->getIssuance()->toArray()),
            $original->getMaterial(),
            $kind === null ? null : AuditActorKind::from($kind)->idFromString($id)
        );
    }

    private function reopenEvidence(DeliveryEnvironment $env): void
    {
        $env->provisioning->auditEvidenceRepository->restoreSnapshot(array_map(
            static fn (AuditEvidence $evidence): AuditEvidence => AuditEvidence::fromArray($evidence->toArray()),
            $env->provisioning->auditEvidenceRepository->all()
        ));
    }

    /** @return array<string, int|string|null> */
    private function facts(
        DeliveryEnvironment $env,
        int $beforeRevision,
        int $afterRevision,
        string $before,
        string $after
    ): array {
        return [
            'credential_id'       => $env->issuance->getCredentialId()->toString(),
            'credential_revision' => $env->issuance->getCredentialRevision(),
            'delivery_id'         => $env->issuance->getDeliveryId()->toString(),
            'before_revision'     => $beforeRevision,
            'after_revision'      => $afterRevision,
            'before'              => $before,
            'after'               => $after,
            'failure'             => null
        ];
    }
}
