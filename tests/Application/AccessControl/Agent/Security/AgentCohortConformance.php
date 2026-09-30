<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\AgentCohortFixture;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\DeliveryConformanceFixture;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

/** Consumer-bindable observable outcomes through actual package services, not readiness booleans */
abstract class AgentCohortConformance extends DeliveryConformance
{
    /** @return iterable<string, array{string, string}> */
    public static function incompatibleWriters(): iterable
    {
        $failures = [
            'missing storage',
            'storage outage',
            'storage version',
            'obsolete canonical marker',
            'unknown canonical marker',
            'destination version',
            ...AgentOperationContract::REQUIRED_CAPABILITIES
        ];
        foreach ($failures as $failure) {
            $paths = ['provision', 'rotation', 'revocation', 'discovery', 'delivery', 'rewrap', 'expiry', 'cleanup'];
            foreach ($paths as $path) {
                yield $failure.' '.$path => [$failure, $path];
            }
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function switchBoundaries(): iterable
    {
        foreach (['claim', 'admission', 'materialized', 'accepted'] as $boundary) {
            yield $boundary.' incompatible' => [$boundary, false];
            yield $boundary.' compatible next generation' => [$boundary, true];
        }
    }

    #[DataProvider('incompatibleWriters')]
    public function test_incompatible_restarted_workers_do_not_mutate_or_materialize(
        string $failure,
        string $path
    ): void {
        $fixture = $this->newFixture();
        $original = $fixture->original();
        if ($path === 'cleanup') {
            self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $original));
            $this->revoke($fixture, $original);
        }

        if ($path === 'expiry' || $path === 'cleanup') {
            $fixture->advance(172801);
        }

        $stored = $fixture->stored($original);
        $agent = $fixture->ports()->agents->getById($original->getAgentId());
        $before = $fixture->counts();
        $order = $fixture->highWater($original->getDestination());
        $fixture->breakCohort($failure);
        $fixture->restart();
        try {
            if ($path === 'provision') {
                $this->provision(
                    $fixture,
                    new AgentOperationKey($original->getKey()->getScope(), AgentOperationId::generate()),
                    $original->getDestination()
                );
                self::fail('Incompatible provision must reject.');
            } elseif ($path === 'rotation') {
                $this->rotate($fixture, $original);
                self::fail('Incompatible rotation must reject.');
            } elseif ($path === 'revocation') {
                $this->revoke($fixture, $original);
                self::fail('Incompatible revocation must reject.');
            } elseif ($path === 'discovery') {
                $this->recover($fixture, $original);
                self::fail('Incompatible discovery is unavailable, not empty.');
            } elseif ($path === 'delivery') {
                self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($fixture, $original));
            } else {
                $service = $this->maintenance($fixture);
                $result = match ($path) {
                    'rewrap' => $service->rewrap(
                        $original->getKey(),
                        $original->getDestination(),
                        $original->getDeliveryId(),
                        new AgentDeliveryKeyVersion('test-key-v2')
                    ),
                    'expiry' => $service->expire(
                        $original->getKey(),
                        $original->getDestination(),
                        $original->getDeliveryId()
                    ),
                    'cleanup' => $service->cleanup(
                        $original->getKey(),
                        $original->getDestination(),
                        $original->getDeliveryId()
                    ),
                    default => throw new LogicException('Unknown conformance path.')
                };
                self::assertSame(AgentMaintenanceResult::UNAVAILABLE, $result);
            }
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertContains($path, ['provision', 'rotation', 'revocation', 'discovery']);
            self::assertSame(AgentOperationFailure::UNAVAILABLE, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            $this->assertSafe($fixture, [
                $agentOperationRejectedException->getMessage(),
                $agentOperationRejectedException->getTraceAsString()
            ]);
        }

        self::assertEquals($stored, $fixture->stored($original));
        self::assertEquals($agent, $fixture->ports()->agents->getById($original->getAgentId()));
        self::assertSame($order, $fixture->highWater($original->getDestination()));
        $after = $fixture->counts();
        foreach (['generations', 'decryptions', 'sink', 'audit'] as $counter) {
            self::assertSame($before[$counter], $after[$counter], $counter);
        }

        // Compatible historical safe reads stay available; incompatibility never means absent issuance.
        self::assertTrue($this->readOperation($fixture, $original)->isConfirmed());
    }

    public function test_compatible_cohort_retains_real_rotation_delivery_maintenance_and_revocation(): void
    {
        $fixture = $this->newFixture();
        $fixture->switchCohort(2);
        $fixture->restart();

        $original = $fixture->original();
        $successor = $this->rotate($fixture, $original);
        self::assertSame(2, $fixture->stored($successor)->getCanonicalVersion());
        self::assertSame(
            AgentMaintenanceResult::REWRAPPED,
            $this->maintenance($fixture)->rewrap(
                $successor->getKey(),
                $successor->getDestination(),
                $successor->getDeliveryId(),
                new AgentDeliveryKeyVersion('test-key-v2')
            )
        );
        self::assertSame(
            [$successor->getDeliveryId()->toString() => AgentDeliveryResult::DELIVERED],
            $this->recover($fixture, $successor)
        );
        $this->revoke($fixture, $successor);
        self::assertNull($fixture->stored($successor)->getMaterial());
        self::assertSame(2, $fixture->counts()['audit'] - 1);
    }

    #[DataProvider('switchBoundaries')]
    public function test_cohort_switch_never_acknowledges_stale_admission_and_restart_recovers(
        string $boundary,
        bool $compatible
    ): void {
        $fixture = $this->newFixture();
        $original = $fixture->original();
        $fixture->pause($boundary, static function () use ($fixture, $compatible): void {
            if ($compatible) {
                $fixture->switchCohort(2);
            } else {
                $fixture->breakCohort('storage version');
            }
        });
        $result = $this->deliver($fixture, $original);
        if ($boundary === 'claim' && $compatible) {
            self::assertSame(AgentDeliveryResult::DELIVERED, $result);
        } else {
            self::assertNotSame(AgentDeliveryResult::DELIVERED, $result);
            self::assertNull($fixture->stored($original)->getReceipt());
            self::assertNotNull($fixture->stored($original)->getMaterial());
        }

        if (!$compatible && in_array($boundary, ['claim', 'admission'], true)) {
            self::assertSame(0, $fixture->counts()['decryptions']);
            self::assertSame(0, $fixture->counts()['sink']);
        }

        $fixture->switchCohort(3);
        $fixture->restart();
        $fixture->advance(61);
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $original));
        self::assertSame(1, $fixture->counts()['generations']);
        self::assertSame(1, $fixture->counts()['audit']);
        self::assertEquals($original, $fixture->stored($original)->getIssuance());
    }

    public function test_cohort_switch_contends_with_live_transaction_not_just_startup_check(): void
    {
        $fixture = $this->newFixture();
        $attempted = false;
        $fixture->pause('fenced', static function () use ($fixture, &$attempted): void {
            $attempted = true;
            try {
                $fixture->switchCohort(2);
                self::fail('A competing switch must not commit through the live transaction fence.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::CONTENTION, $agentOperationRejectedException->getReason());
            }
        });
        self::assertSame(AgentDeliveryResult::DELIVERED, $this->deliver($fixture, $fixture->original()));
        self::assertTrue($attempted);
        $fixture->switchCohort(2);
        $fixture->breakCohort('obsolete canonical marker');
        self::assertSame(AgentDeliveryResult::UNAVAILABLE, $this->deliver($fixture, $fixture->original()));
    }

    abstract protected function newFixture(): DeliveryConformanceFixture&AgentCohortFixture;
}
