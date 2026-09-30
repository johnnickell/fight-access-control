<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialDeliveryService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryMaintenanceService;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentDeliveryResult;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContract;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\Conformance\InMemoryAgentRestoration;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\MaintenanceEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentOperationContract::class)]
#[CoversClass(AgentCredentialDeliveryService::class)]
#[CoversClass(AgentDeliveryMaintenanceService::class)]
final class AgentRestorationWriterTest extends TestCase
{
    public function test_reconciled_restore_denies_old_admission_even_at_the_same_state_revision(): void
    {
        $env = new DeliveryEnvironment();
        $restore = new InMemoryAgentRestoration($env);
        $restore->savePackageState('old');

        $env->sink->afterStage = static function () use ($restore): void {
            $restore->savePackageState('forward');
            $restore->restorePackageState('old');
            self::assertTrue($restore->reconcilePackageState('forward'));
        };
        self::assertSame(AgentDeliveryResult::REJECTED, $env->deliver());
        self::assertNull($env->operation()->getReceipt());
        self::assertSame(1, $env->sink->calls);
        self::assertSame(1, $env->provisioning->generations);
        self::assertSame($env->issuance->getDestinationWriteVersion(), $env->sink->highWater[
            $env->issuance->getDestination()->getId()->toString()
        ]);
        $env->sink->afterStage = null;
        $env->clock->advance(61);
        self::assertSame(AgentDeliveryResult::DELIVERED, $env->deliver());
        self::assertEquals($env->issuance, $env->operation()->getIssuance());
    }

    public function test_reconciled_restore_cannot_acknowledge_old_cleanup_and_tombstone_survives_retry(): void
    {
        $env = new MaintenanceEnvironment();
        $delivery = $env->delivery;
        $restore = new InMemoryAgentRestoration($delivery);
        $restore->savePackageState('old');
        self::assertSame(AgentDeliveryResult::DELIVERED, $delivery->deliver());
        $delivery->revoke();
        $delivery->clock->advance(172801);
        $delivery->sink->afterCleanup = static function () use ($restore): void {
            $restore->savePackageState('forward');
            $restore->restorePackageState('old');
            self::assertTrue($restore->reconcilePackageState('forward'));
        };
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->cleanup());
        self::assertFalse($delivery->operation()->isSinkCleaned());
        self::assertNull($delivery->sink->stagedBytes($delivery->issuance));
        self::assertNull($delivery->operation()->getMaterial());
        $delivery->sink->afterCleanup = null;
        self::assertSame(AgentMaintenanceResult::CLEANED, $env->cleanup());
        self::assertTrue($delivery->operation()->isSinkCleaned());
        self::assertSame(1, $delivery->provisioning->generations);
        self::assertCount(2, $delivery->provisioning->audit->all());
    }

    public function test_rewrap_and_restoration_must_share_the_conflicting_fence(): void
    {
        $env = new MaintenanceEnvironment();
        $restore = new InMemoryAgentRestoration($env->delivery);
        $restore->savePackageState('old');

        $before = $restore->packageStateFingerprint();
        $env->afterRewrap = static function () use ($restore): void {
            try {
                $restore->restorePackageState('old');
                self::fail('Restore must not replace state beneath a material writer.');
            } catch (AgentOperationRejectedException $agentOperationRejectedException) {
                self::assertSame(AgentOperationFailure::CONTENTION, $agentOperationRejectedException->getReason());
                throw $agentOperationRejectedException;
            }
        };
        self::assertSame(AgentMaintenanceResult::REJECTED, $env->rewrapOriginal());
        self::assertSame($before, $restore->packageStateFingerprint());
        $env->afterRewrap = null;
        self::assertSame(AgentMaintenanceResult::REWRAPPED, $env->rewrapOriginal());
    }
}
