<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\QueryHandler\ListDueAgentDeliveriesHandler;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryRecoveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryPolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliverySchedule;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationLimits;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\ReceiptLookupSink;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(AgentDeliveryRecoveryService::class)]
#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentCredentialOperation::class)]
final class AgentDeliveryRecoveryServiceTest extends TestCase
{
    public function test_default_only_restarted_scheduler_discovers_and_delivers_without_caller_or_event_input(): void
    {
        $env = new DeliveryEnvironment();
        $issuance = $env->issuance->toArray();
        $service = $this->scheduler($env);
        self::assertEquals($env->clock->now()->modify('+30 seconds'), $service->nextRunAt());
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $service->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination())
        );
        self::assertSame([], $this->recover($env));
        self::assertSame($issuance, $env->operation()->getIssuance()->toArray());
        self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
        self::assertSame(1, $env->provisioning->generations);
        self::assertCount(1, $env->provisioning->agents->all());
        self::assertCount(1, $env->provisioning->audit->all());
        self::assertSame(1, $env->sink->calls);
        self::assertNull($env->operation()->getMaterial());
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getStatus()->getDeliveryDisposition());
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
    public function test_restart_resolves_persisted_uncertainty_without_reissuing_or_unnecessary_decryption(
        int $stage,
        bool $persist
    ): void {
        $env = new DeliveryEnvironment();
        $sink = new ReceiptLookupSink($env);
        $env->transaction->uncertainAt = $stage;
        $env->transaction->persistUncertain = $persist;
        self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($env, $sink));
        self::assertSame($stage === 3 ? 1 : 0, $env->decipher->calls);
        self::assertSame($persist ? $stage : $stage - 1, $env->operation()->getStateRevision());
        $env->transaction->uncertainAt = null;
        $env->clock->advance(61);
        // New Application services and lookup adapter use persisted operation/sink state, never a saved result/token.
        $results = $this->recover($env);
        if ($stage === 3 && $persist) {
            self::assertSame([], $results);
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env, new ReceiptLookupSink($env)));
        } else {
            self::assertSame([$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED], $results);
        }

        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->provisioning->generations);
        self::assertCount(1, $env->provisioning->audit->all());
        self::assertSame($env->sink->receiptFor($env->issuance), $env->operation()->getReceipt());
        self::assertNull($env->operation()->getMaterial());
    }

    /** @return iterable<string, array{int, bool}> */
    public static function crashBoundaries(): iterable
    {
        foreach ([1 => 'claim', 2 => 'admission', 3 => 'outcome'] as $stage => $name) {
            yield 'before '.$name => [$stage, false];
            yield 'after '.$name => [$stage, true];
        }
    }

    #[DataProvider('crashBoundaries')]
    public function test_restart_after_each_durable_boundary_uses_only_committed_state(int $stage, bool $after): void
    {
        $env = new DeliveryEnvironment();
        $crash = static function (int $current) use ($stage): void {
            if ($current === $stage) {
                throw new RuntimeException('Simulated process loss.');
            }
        };
        if ($after) {
            $env->transaction->afterCommit = $crash;
        } else {
            $env->provisioning->operations->beforeDeliveryWrite = static function () use ($env, $crash): void {
                $crash($env->transaction->commits);
            };
        }

        self::assertSame(
            $after ? AgentDeliveryResult::INDETERMINATE : AgentDeliveryResult::UNAVAILABLE,
            $this->deliver($env, new ReceiptLookupSink($env))
        );
        self::assertSame($after ? $stage : $stage - 1, $env->operation()->getStateRevision());
        $env->transaction->afterCommit = null;
        $env->provisioning->operations->beforeDeliveryWrite = null;
        $env->clock->advance(61);
        $this->recover($env);
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getStatus()->getDeliveryDisposition());
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->provisioning->generations);
    }

    public function test_current_admission_reconciles_exact_receipt_but_never_reinvokes_from_recovered_admission(): void
    {
        foreach ([2, 3] as $stage) {
            $env = new DeliveryEnvironment();
            $env->transaction->uncertainAt = $stage;
            $env->transaction->persistUncertain = $stage === 2;
            self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($env, new ReceiptLookupSink($env)));
            $env->transaction->uncertainAt = null;
            self::assertSame(
                $stage === 2 ? AgentDeliveryResult::DEFERRED : AgentDeliveryResult::DELIVERED,
                $this->deliver($env, new ReceiptLookupSink($env))
            );
            self::assertSame($stage === 2 ? 0 : 1, $env->decipher->calls);
            self::assertSame($stage === 2 ? 0 : 1, $env->sink->calls);
            self::assertSame(1, $env->operation()->requireAttempt()->getFence());
            if ($stage === 2) {
                $env->clock->advance(15);
                self::assertSame(AgentDeliveryResult::DEFERRED, $this->deliver($env, new ReceiptLookupSink($env)));
                self::assertSame(0, $env->decipher->calls);
                $env->clock->advance(45);
                self::assertSame(
                    [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
                    $this->recover($env)
                );
                self::assertSame(2, $env->operation()->requireAttempt()->getFence());
            }
        }
    }

    public function test_lost_sink_response_reconciles_before_key_access_and_preserves_id_and_slot_order(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->issuance->toArray();
        $env->sink->afterStage = static function (): void {
            throw new RuntimeException('Lost response with unsafe provider diagnostic /key/path');
        };
        self::assertSame(AgentDeliveryResult::RETRYABLE, $this->deliver($env, new ReceiptLookupSink($env)));
        $receipt = $env->sink->receiptFor($env->issuance);
        self::assertNotNull($receipt);
        $env->decipher->failure = AgentDeliveryFailure::TEMPORARY;
        $env->sink->afterStage = null;
        $env->clock->advance(30);
        self::assertSame([], $this->recover($env));
        $env->clock->advance(30);
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($env)
        );
        self::assertSame($receipt, $env->operation()->getReceipt());
        self::assertSame($original, $env->operation()->getIssuance()->toArray());
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertSame(2, $env->operation()->requireAttempt()->getFence());
    }

    public function test_takeover_during_old_invocation_reconciles_receipt_and_fences_old_acknowledgement(): void
    {
        $env = new DeliveryEnvironment();
        $env->sink->afterStage = static function () use ($env): void {
            $env->sink->afterStage = null;
            $env->clock->advance(60);

            $scheduler = new AgentDeliveryRecoveryService(
                new ListDueAgentDeliveriesHandler($env->provisioning->operations, $env->authorization, $env->clock),
                $env->service(sink: new ReceiptLookupSink($env)),
                $env->clock
            );
            self::assertSame(
                [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
                $scheduler->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination())
            );
        };
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($env, new ReceiptLookupSink($env)));
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getStatus()->getDeliveryDisposition());
        self::assertSame(2, $env->operation()->requireAttempt()->getFence());
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->decipher->calls);
    }

    public function test_discovery_allow_cannot_substitute_for_fresh_admission_authority(): void
    {
        $env = new DeliveryEnvironment();
        $env->authorization->afterDiscovery = static function (?AgentIssuance $issuance) use ($env): void {
            if ($issuance !== null) {
                $env->authorization->delegated = false;
            }
        };
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::REJECTED],
            $this->recover($env)
        );
        self::assertSame(0, $env->operation()->getStateRevision());
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
    }

    public function test_lookup_failure_and_slow_absence_never_bypass_admission_deadlines_or_report_success(): void
    {
        $env = new DeliveryEnvironment();
        $sink = new ReceiptLookupSink($env);
        $sink->beforeLookup = static function (): void {
            throw new RuntimeException('Unsafe receipt storage detail');
        };
        self::assertSame(AgentDeliveryResult::RETRYABLE, $this->deliver($env, $sink));
        self::assertNotNull($env->operation()->getMaterial());
        self::assertSame(0, $env->decipher->calls);
        $env->clock->advance(60);
        $sink->beforeLookup = static function () use ($env): void {
            $env->clock->advance(15);
        };
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($env, $sink));
        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->sink->calls);
    }

    public function test_swapped_receipt_is_terminal_and_delivered_material_loss_never_reissues(): void
    {
        $invalid = new DeliveryEnvironment();
        $sink = new ReceiptLookupSink($invalid);
        $sink->wrongReceipt = new AgentDeliveryReceipt('valid-format-wrong-receipt');
        self::assertSame(AgentDeliveryResult::TERMINAL, $this->deliver($invalid, $sink));
        self::assertNull($invalid->operation()->getMaterial());
        self::assertSame(0, $invalid->decipher->calls);
        self::assertSame([], $this->recover($invalid));
        $env = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env, new ReceiptLookupSink($env)));
        $env->sink->forgetMaterial($env->issuance);
        self::assertSame(
            AgentDeliveryResult::RECONCILIATION_REQUIRED,
            $this->deliver($env, new ReceiptLookupSink($env))
        );
        self::assertSame(AgentDeliveryDisposition::DELIVERED, $env->operation()->getStatus()->getDeliveryDisposition());
        self::assertSame([], $this->recover($env));
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->provisioning->generations);
        self::assertCount(1, $env->provisioning->audit->all());
    }

    public function test_temporary_keys_capacity_and_bounded_overrides_preserve_existing_recovery(): void
    {
        $env = new DeliveryEnvironment();
        try {
            $env->provisioning->service(limits: new AgentOperationLimits(512, 1, 1))->provision(
                new AgentOperationKey($env->issuance->getKey()->getScope(), AgentOperationId::generate()),
                $env->provisioning->request
            );
            self::fail('New work at capacity must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::CAPACITY, $agentOperationRejectedException->getReason());
        }

        $material = $env->operation()->getMaterial();
        $env->decipher->failure = AgentDeliveryFailure::TEMPORARY;
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::RETRYABLE],
            $this->recover($env)
        );
        self::assertSame($material, $env->operation()->getMaterial());
        self::assertSame('test-key-v1', $material?->getKeyVersion());
        $env->clock->advance(60);
        $env->decipher->failure = null;
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($env)
        );
        self::assertSame('original-test-secret', $env->sink->stagedBytes($env->issuance));
        $limited = new DeliveryEnvironment();
        $limited->decipher->failure = AgentDeliveryFailure::TEMPORARY;

        $schedule = new AgentDeliverySchedule(1, 90);
        $worker = $this->scheduler($limited, $schedule, new AgentDeliveryPolicy(retrySeconds: 90, maximumAttempts: 1));
        self::assertEquals($limited->clock->now()->modify('+90 seconds'), $worker->nextRunAt());
        self::assertSame(
            [$limited->issuance->getDeliveryId()->toString() => AgentDeliveryResult::RETRYABLE],
            $worker->recover($limited->issuance->getKey()->getScope(), $limited->issuance->getDestination())
        );
        $limited->clock->advance(60);
        self::assertSame([], $this->recover($limited));
        $limited->clock->advance(30);
        self::assertSame(
            [$limited->issuance->getDeliveryId()->toString() => AgentDeliveryResult::TERMINAL],
            $this->recover($limited)
        );
        self::assertSame(1, $limited->decipher->calls);
        self::assertNull($limited->operation()->getMaterial());
    }

    public function test_retention_expiry_and_retirement_are_not_recoverable_delivery_authority(): void
    {
        $env = new DeliveryEnvironment();
        $env->clock->advance(86400);
        $env->authorization->expiresAt = $env->clock->now()->modify('+1 hour');
        self::assertSame(
            [$env->issuance->getDeliveryId()->toString() => AgentDeliveryResult::EXPIRED],
            $this->recover($env)
        );
        self::assertNull($env->operation()->getMaterial());
        self::assertSame([], $this->recover($env));
        self::assertSame(0, $env->decipher->calls);
        $retired = new DeliveryEnvironment();
        $retired->revoke();
        self::assertSame([], $this->recover($retired));
        self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($retired, new ReceiptLookupSink($retired)));
        self::assertSame(0, $retired->decipher->calls);
    }

    public function test_scheduler_does_not_drain_more_than_one_bounded_batch(): void
    {
        $env = new DeliveryEnvironment();
        for ($index = 0; $index < 2; ++$index) {
            $env->provisioning->service()->provision(
                new AgentOperationKey($env->issuance->getKey()->getScope(), AgentOperationId::generate()),
                $env->provisioning->request
            );
        }

        $before = $env->transaction->commits;
        $results = $this->scheduler($env, new AgentDeliverySchedule(1))->recover(
            $env->issuance->getKey()->getScope(),
            $env->issuance->getDestination()
        );
        self::assertCount(1, $results);
        self::assertSame(1, $env->provisioning->operations->dueReads);
        self::assertLessThanOrEqual(3, $env->transaction->commits - $before);
        self::assertCount(3, $env->provisioning->operations->operations);
    }

    public function test_current_receipt_reconciliation_rejects_revocation_aba_and_expiry_before_acknowledgement(): void
    {
        foreach (['revoked', 'aba', 'deadline'] as $case) {
            $env = new DeliveryEnvironment();
            $env->transaction->uncertainAt = 3;
            self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($env, new ReceiptLookupSink($env)));
            $env->transaction->uncertainAt = null;
            $sink = new ReceiptLookupSink($env);
            $sink->beforeLookup = static function () use ($env, $case): void {
                if ($case === 'revoked') {
                    $env->revoke();
                } elseif ($case === 'deadline') {
                    $env->clock->advance(15);
                } else {
                    $env->provisioning->authorization->changeAuthority(static function () use ($env): void {
                        $env->authorization->permitted = false;
                    });
                    $env->provisioning->authorization->changeAuthority(static function () use ($env): void {
                        $env->authorization->permitted = true;
                    });
                }
            };
            self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($env, $sink));
            self::assertNotSame(
                AgentDeliveryDisposition::DELIVERED,
                $env->operation()->getStatus()->getDeliveryDisposition()
            );
            self::assertSame(1, $env->decipher->calls);
            self::assertSame(1, $env->sink->calls);
        }
    }

    public function test_completed_history_without_receipt_or_with_unavailable_sink_cannot_recreate_material(): void
    {
        $env = new DeliveryEnvironment();
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($env, new ReceiptLookupSink($env)));
        $env->sink->beforeVerify = static function (): void {
            throw new RuntimeException('Untrusted sink detail');
        };
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($env, new ReceiptLookupSink($env)));
        $env->sink->beforeVerify = null;
        $original = $env->operation();
        $env->provisioning->operations->operations[$env->issuance->getKey()->toString()] = new AgentCredentialOperation(
            1,
            $original->getCanonicalRequest(),
            $env->issuance,
            null,
            AgentDeliveryDisposition::DELIVERED
        );
        self::assertSame(
            AgentDeliveryResult::RECONCILIATION_REQUIRED,
            $this->deliver($env, new ReceiptLookupSink($env))
        );
        self::assertSame(1, $env->decipher->calls);
        self::assertSame(1, $env->sink->calls);
    }

    public function test_storage_outage_during_restart_is_unavailable_not_empty_work(): void
    {
        $env = new DeliveryEnvironment();
        $env->provisioning->operations->afterDueRead = static function (): void {
            throw new RuntimeException('Unsafe storage path');
        };
        try {
            $this->recover($env);
            self::fail('Unavailable storage must not look like an empty queue.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
        }

        self::assertSame(0, $env->decipher->calls);
        self::assertSame(0, $env->operation()->getStateRevision());
    }

    /** @return array<string, AgentDeliveryResult> */
    private function recover(DeliveryEnvironment $env): array
    {
        return $this->scheduler($env)->recover($env->issuance->getKey()->getScope(), $env->issuance->getDestination());
    }

    private function scheduler(
        DeliveryEnvironment $env,
        ?AgentDeliverySchedule $schedule = null,
        ?AgentDeliveryPolicy $policy = null
    ): AgentDeliveryRecoveryService {
        return new AgentDeliveryRecoveryService(
            new ListDueAgentDeliveriesHandler($env->provisioning->operations, $env->authorization, $env->clock),
            $env->service($policy, new ReceiptLookupSink($env)),
            $env->clock,
            $schedule ?? new AgentDeliverySchedule()
        );
    }

    private function deliver(DeliveryEnvironment $env, ReceiptLookupSink $sink): AgentDeliveryResult
    {
        return $env->service(sink: $sink)->deliver(
            $env->issuance->getKey(),
            $env->issuance->getDestination(),
            $env->issuance->getDeliveryId()
        );
    }
}
