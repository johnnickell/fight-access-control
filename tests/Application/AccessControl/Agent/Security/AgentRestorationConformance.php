<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentDeliveryFailedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\AgentRestorationFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\DeliveryConformanceFixture;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Real public operations against separately retained external history; reference restore is simulated, not physical */
abstract class AgentRestorationConformance extends DeliveryConformance
{
    /** @return iterable<string, array{string, string}> */
    public static function restoredWriters(): iterable
    {
        foreach (['issuance', 'accepted', 'superseded', 'revoked', 'cleaned'] as $history) {
            $paths = [
                'provision', 'retry', 'rotation', 'revocation', 'discovery', 'delivery', 'rewrap', 'expiry', 'cleanup'
            ];
            foreach ($paths as $path) {
                yield $history.' '.$path => [$history, $path];
            }
        }
    }

    /** @return iterable<string, array{string}> */
    public static function histories(): iterable
    {
        foreach (['issuance', 'accepted', 'superseded', 'revoked', 'cleaned'] as $history) {
            yield $history => [$history];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function evidenceFailures(): iterable
    {
        $failures = [
            'missing', 'unavailable', 'receipt', 'order', 'tombstone',
            'namespace', 'caller_type', 'caller_id', 'operation_id', 'delivery_id', 'agent_id',
            'credential_id', 'credential_revision', 'destination_id', 'destination_revision',
            'destination_write_version', 'issued_at'
        ];
        foreach ($failures as $failure) {
            yield $failure => [$failure];
        }
    }

    /** @return iterable<string, array{string}> */
    public static function restoreBoundaries(): iterable
    {
        foreach (['claim', 'admission', 'materialized', 'accepted'] as $boundary) {
            yield $boundary => [$boundary];
        }
    }

    #[DataProvider('restoredWriters')]
    public function test_stale_package_state_never_reopens_unsafe_public_paths(string $history, string $path): void
    {
        $fixture = $this->newFixture();
        $target = $this->prepareHistory($fixture, $history);
        $receipt = $fixture->receipt($target);
        $water = $fixture->highWater($target->getDestination());
        $fixture->restorePackageState('old');
        $fixture->restart();

        $status = $fixture->ports()->operations->getStatusByKey($target->getKey());
        $agent = $fixture->ports()->agents->getById($target->getAgentId());
        $before = $fixture->counts();
        $fingerprint = $fixture->packageStateFingerprint();
        $this->assertUnavailable($fixture, $target, $path);
        self::assertSame($fingerprint, $fixture->packageStateFingerprint());
        self::assertTrue($agent == $fixture->ports()->agents->getById($target->getAgentId()));
        self::assertEquals($status, $fixture->ports()->operations->getStatusByKey($target->getKey()));
        self::assertEquals($receipt, $fixture->receipt($target));
        self::assertSame($water, $fixture->highWater($target->getDestination()));
        $this->assertNoSensitiveEffects($fixture, $before);
        self::assertFalse($fixture->reconcilePackageState('old'), 'An old local snapshot cannot attest itself.');
        $this->assertSafe($fixture, [$status?->toArray()]);
    }

    #[DataProvider('histories')]
    public function test_verified_repair_preserves_keys_order_terminal_state_and_identity(string $history): void
    {
        $fixture = $this->newFixture();
        $target = $this->prepareHistory($fixture, $history);
        $retained = $fixture->stored($target);
        $water = $fixture->highWater($target->getDestination());
        $counts = $fixture->counts();
        $fixture->restorePackageState('old');
        self::assertTrue($fixture->reconcilePackageState('current'));
        $fixture->restart();
        self::assertTrue($retained == $fixture->stored($target), 'Repair must retain complete original state.');
        self::assertEquals(
            $target,
            $this->provision($fixture, $target->getKey(), $target->getDestination())
        );
        $this->assertNoSensitiveEffects($fixture, $counts);
        self::assertSame($water, $fixture->highWater($target->getDestination()));
        self::assertTrue($this->readOperation($fixture, $target)->isConfirmed());
        if (in_array($history, ['superseded', 'revoked', 'cleaned'], true)) {
            self::assertNull($fixture->stored($target)->getMaterial());
            self::assertNotSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $target));
            $this->assertNoSensitiveEffects($fixture, $counts);
        } else {
            $fixture->advance(61);
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $target));
        }

        $next = $this->provision(
            $fixture,
            new AgentOperationKey($target->getKey()->getScope(), AgentOperationId::generate()),
            $target->getDestination()
        );
        self::assertGreaterThan($water, $next->getDestinationWriteVersion());
        self::assertNotEquals($target->getDeliveryId(), $next->getDeliveryId());
    }

    #[DataProvider('evidenceFailures')]
    public function test_incomplete_or_mismatched_external_evidence_cannot_enable_repaired_state(string $failure): void
    {
        $fixture = $this->newFixture();
        $target = $this->prepareHistory($fixture, $failure === 'tombstone' ? 'cleaned' : 'accepted');
        $fixture->restorePackageState('old');
        $fixture->breakRestorationEvidence($failure);

        $stored = $fixture->stored($target);
        $fingerprint = $fixture->packageStateFingerprint();
        $before = $fixture->counts();
        self::assertFalse($fixture->reconcilePackageState('current'));
        self::assertSame($fingerprint, $fixture->packageStateFingerprint());
        self::assertTrue($stored == $fixture->stored($target), 'Failed reconciliation must not apply partial repair.');
        $this->assertUnavailable($fixture, $target, 'retry');
        $this->assertUnavailable($fixture, $target, 'delivery');
        $this->assertNoSensitiveEffects($fixture, $before);
    }

    #[DataProvider('restoreBoundaries')]
    public function test_restore_between_stages_denies_acknowledgement_and_preserves_bytes(string $boundary): void
    {
        $fixture = $this->newFixture();
        $target = $fixture->original();
        $secret = $fixture->preparedBytes($target);
        $fixture->savePackageState('old');
        $fixture->pause($boundary, static function () use ($fixture): void {
            $fixture->savePackageState('current');
            $fixture->restorePackageState('old');
        });
        self::assertNotSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $target));
        self::assertNull($fixture->stored($target)->getReceipt());
        if ($boundary === 'claim' || $boundary === 'admission') {
            self::assertSame(0, $fixture->counts()['decryptions']);
            self::assertSame(0, $fixture->counts()['sink']);
        } else {
            self::assertTrue($secret === $fixture->stagedBytes($target));
            self::assertSame(1, $fixture->counts()['sink']);
        }

        $fixture->restart();
        self::assertTrue($fixture->reconcilePackageState('current'));
        $fixture->advance(61);
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $target));
        self::assertEquals($target, $fixture->stored($target)->getIssuance());
        self::assertTrue($secret === $fixture->stagedBytes($target));
        self::assertSame(1, $fixture->counts()['generations']);
        self::assertSame(1, $fixture->counts()['audit']);
        // Delivery has no activation/use API; the consumer must prove those current-authority fences separately.
    }

    public function test_restoration_contends_with_the_live_writer_fence_and_is_not_a_startup_check(): void
    {
        $fixture = $this->newFixture();
        $fixture->savePackageState('old');

        $attempted = false;
        $fixture->pause('fenced', static function () use ($fixture, &$attempted): void {
            $attempted = true;
            try {
                $fixture->restorePackageState('old');
                self::fail('Restore cannot replace data beneath a live writer.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::CONTENTION, $agentOperationRejectedException->getReason());
            }
        });
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $fixture->original()));
        self::assertTrue($attempted);
        $fixture->restorePackageState('old');
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($fixture, $fixture->original()));
    }

    public function test_reconciled_cleanup_retains_the_sink_tombstone_against_delayed_replay(): void
    {
        $fixture = $this->newFixture();
        $target = $fixture->original();
        $invocation = new AgentCredentialInvocation($target, $fixture->preparedBytes($target));
        $fixture->savePackageState('old');
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $target));
        $this->revoke($fixture, $target);
        $fixture->advance(172801);
        self::assertSame(AgentMaintenanceResult::CLEANED, $this->maintenance($fixture)->cleanup(
            $target->getKey(),
            $target->getDestination(),
            $target->getDeliveryId()
        ));
        $fixture->savePackageState('current');
        $fixture->restorePackageState('old');
        self::assertTrue($fixture->reconcilePackageState('current'));
        try {
            $fixture->ports()->sink->stage($invocation);
            self::fail('A reconciled tombstone cannot admit delayed bytes.');
        } catch (AgentDeliveryFailedException) {
            self::assertNull($fixture->stagedBytes($target));
        }

        self::assertTrue($fixture->stored($target)->isSinkCleaned());
        self::assertNull($fixture->stored($target)->getMaterial());
        self::assertSame($target->getDestinationWriteVersion(), $fixture->highWater($target->getDestination()));
    }

    public function test_reconciliation_never_substitutes_for_current_delivery_authorization(): void
    {
        foreach (['caller', 'permission', 'delegation', 'destination'] as $change) {
            $fixture = $this->newFixture();
            $target = $this->prepareHistory($fixture, 'accepted');
            $fixture->restorePackageState('old');
            self::assertTrue($fixture->reconcilePackageState('current'));
            $fixture->restart();
            $fixture->advance(61);
            $fixture->changeAuthority($change, $target);

            $before = $fixture->counts();
            self::assertSame(AgentDeliveryResult::REJECTED, $this->deliver($fixture, $target));
            $this->assertNoSensitiveEffects($fixture, $before);
            self::assertNull($fixture->stored($target)->getReceipt());
        }
    }

    abstract protected function newFixture(): DeliveryConformanceFixture&AgentRestorationFixture;

    private function prepareHistory(
        DeliveryConformanceFixture&AgentRestorationFixture $fixture,
        string $history
    ): AgentIssuance {
        $seed = $fixture->original();
        $fixture->savePackageState('old');
        $target = $this->provision(
            $fixture,
            new AgentOperationKey($seed->getKey()->getScope(), AgentOperationId::generate()),
            $seed->getDestination()
        );
        if ($history !== 'issuance') {
            $fixture->savePackageState('old');
        }

        if ($history === 'accepted') {
            $fixture->loseCommit(3, false);
            self::assertSame(AgentDeliveryResult::INDETERMINATE, $this->deliver($fixture, $target));
            self::assertNotNull($fixture->receipt($target));
            self::assertNull($fixture->stored($target)->getReceipt());
        } else {
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $target));
        }

        if ($history === 'superseded') {
            $successor = $this->rotate($fixture, $target);
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $successor));
        } elseif ($history === 'revoked' || $history === 'cleaned') {
            $this->revoke($fixture, $target);
            if ($history === 'cleaned') {
                $fixture->advance(172801);
                self::assertSame(AgentMaintenanceResult::CLEANED, $this->maintenance($fixture)->cleanup(
                    $target->getKey(),
                    $target->getDestination(),
                    $target->getDeliveryId()
                ));
            }
        }

        $fixture->savePackageState('current');

        return $target;
    }

    private function assertUnavailable(DeliveryConformanceFixture $fixture, AgentIssuance $target, string $path): void
    {
        try {
            if ($path === 'provision' || $path === 'retry') {
                $key = $target->getKey();
                if ($path === 'provision') {
                    $key = new AgentOperationKey($key->getScope(), AgentOperationId::generate());
                }

                $this->provision($fixture, $key, $target->getDestination());
                self::fail('Restored state must not issue or resolve as writer-ready.');
            } elseif ($path === 'rotation') {
                $this->rotate($fixture, $target);
                self::fail('Restored state must not rotate.');
            } elseif ($path === 'revocation') {
                $this->revoke($fixture, $target);
                self::fail('Restored state must not mutate lifecycle.');
            } elseif ($path === 'discovery') {
                $this->recover($fixture, $target);
                self::fail('Unreconciled discovery is unavailable, not empty.');
            } elseif ($path === 'delivery') {
                self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($fixture, $target));
            } else {
                $service = $this->maintenance($fixture);
                $key = $target->getKey();
                $destination = $target->getDestination();
                $id = $target->getDeliveryId();
                $result = match ($path) {
                    'rewrap' => $service->rewrap($key, $destination, $id, new AgentDeliveryKeyVersion('test-key-v2')),
                    'expiry' => $service->expire($key, $destination, $id),
                    'cleanup' => $service->cleanup($key, $destination, $id),
                    default => throw new LogicException('Unknown restoration scenario.')
                };
                self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $result);
            }
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertContains($path, ['provision', 'retry', 'rotation', 'revocation', 'discovery']);
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            $this->assertSafe($fixture, [
                $agentOperationRejectedException->getMessage(),
                $agentOperationRejectedException->getTraceAsString()
            ]);
        }
    }

    /** @param array{decryptions: int, sink: int, transactions: int, generations: int, audit: int, events: int} $before */
    private function assertNoSensitiveEffects(DeliveryConformanceFixture $fixture, array $before): void
    {
        $after = $fixture->counts();
        foreach (['generations', 'decryptions', 'sink', 'audit'] as $counter) {
            self::assertSame($before[$counter], $after[$counter], $counter);
        }
    }
}
