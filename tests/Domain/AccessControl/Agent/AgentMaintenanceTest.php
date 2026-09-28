<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentOperationRejectedException;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialOperation;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryMaterial;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationFailure;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\EncryptedCredentialMaterial;
use Fight\Test\AccessControl\Application\AccessControl\Agent\Service\DeliveryEnvironment;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(AgentMaintenancePolicy::class)]
#[CoversClass(AgentDeliveryKeyVersion::class)]
#[CoversClass(ListAgentDeliveryMaintenance::class)]
#[CoversClass(CountAgentDeliveryKeyReferences::class)]
#[CoversClass(AgentCredentialOperation::class)]
final class AgentMaintenanceTest extends TestCase
{
    public function test_defaults_and_override_boundaries_keep_finite_recovery_windows(): void
    {
        $now = new DateTimeImmutable('2026-09-27T12:00:00Z');
        $default = new AgentMaintenancePolicy();
        self::assertSame(['batch_size' => 50, 'cleanup_grace_seconds' => 86400], $default->toArray());
        self::assertSame(50, $default->getBatchSize());
        self::assertEquals($now->modify('+1 day'), $default->cleanupAfter($now));
        foreach ([[1, 1], [100, 604800]] as [$batch, $grace]) {
            $policy = new AgentMaintenancePolicy($batch, $grace);
            self::assertSame($batch, $policy->getBatchSize());
            self::assertEquals($now->modify('+'.$grace.' seconds'), $policy->cleanupAfter($now));
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function invalidPolicies(): iterable
    {
        yield 'empty batch' => [0, 1];
        yield 'oversized batch' => [101, 1];
        yield 'zero grace' => [50, 0];
        yield 'unbounded grace' => [50, 604801];
    }

    #[DataProvider('invalidPolicies')]
    public function test_invalid_limits_reject(int $batch, int $grace): void
    {
        $this->expectException(AgentOperationRejectedException::class);
        new AgentMaintenancePolicy($batch, $grace);
    }

    public function test_safe_requests_round_trip_with_and_without_cursor(): void
    {
        $query = $this->query();
        self::assertSame($query->toArray(), ListAgentDeliveryMaintenance::fromArray($query->toArray())->toArray());
        self::assertNull($query->getAfter());
        $query = new ListAgentDeliveryMaintenance(
            $query->getScope(),
            $query->getDestination(),
            AgentMaintenanceWork::CLEANUP,
            new AgentMaintenancePolicy(100, 604800),
            AgentDeliveryId::generate()
        );
        $roundTrip = ListAgentDeliveryMaintenance::fromArray($query->toArray());
        self::assertSame($query->toArray(), $roundTrip->toArray());
        self::assertSame(AgentMaintenanceWork::CLEANUP, $roundTrip->getWork());
        self::assertSame(100, $roundTrip->getPolicy()->getBatchSize());
        self::assertTrue($query->getAfter()?->equals($roundTrip->getAfter()));
        $count = new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('wrapping.v2_1-test'));
        $restored = CountAgentDeliveryKeyReferences::fromArray($count->toArray());
        self::assertSame($count->toArray(), $restored->toArray());
        self::assertSame('wrapping.v2_1-test', $restored->getVersion()->toString());
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidQueryFields(): iterable
    {
        yield 'namespace' => ['namespace', false];
        yield 'caller type' => ['caller_type', []];
        yield 'caller identity' => ['caller_id', 1];
        yield 'destination' => ['destination_id', 'invalid'];
        yield 'binding' => ['destination_revision', 0];
        yield 'batch string' => ['batch_size', '50'];
        yield 'batch bound' => ['batch_size', 101];
        yield 'grace string' => ['cleanup_grace_seconds', '1'];
        yield 'grace bound' => ['cleanup_grace_seconds', 0];
        yield 'work type' => ['work', []];
        yield 'unknown work' => ['work', 'all'];
        yield 'cursor type' => ['after', 1];
        yield 'cursor format' => ['after', 'invalid'];
    }

    #[DataProvider('invalidQueryFields')]
    public function test_invalid_query_data_fails_safely(string $field, mixed $value): void
    {
        $data = $this->query()->toArray();
        $data[$field] = $value;
        $this->expectException(AgentOperationRejectedException::class);
        ListAgentDeliveryMaintenance::fromArray($data);
    }

    public function test_every_canonical_query_field_is_required(): void
    {
        $data = $this->query()->toArray();
        foreach (array_keys($data) as $field) {
            $missing = $data;
            unset($missing[$field]);
            try {
                ListAgentDeliveryMaintenance::fromArray($missing);
                self::fail('Absent field accepted: '.$field);
            } catch (AgentOperationRejectedException $failure) {
                self::assertSame(AgentOperationFailure::INVALID_REQUEST, $failure->getReason());
            }
        }
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAccounting(): iterable
    {
        yield 'missing version' => [[]];
        yield 'non-string' => [['key_version' => 1]];
        yield 'path' => [['key_version' => '/secret/key']];
        yield 'empty version' => [['key_version' => '']];
        yield 'long version' => [['key_version' => str_repeat('a', 65)]];
    }

    /** @param array<string, mixed> $data */
    #[DataProvider('invalidAccounting')]
    public function test_invalid_accounting_request_rejects(array $data): void
    {
        try {
            CountAgentDeliveryKeyReferences::fromArray($data);
            self::fail('Invalid accounting request must reject.');
        } catch (AgentOperationRejectedException $agentOperationRejectedException) {
            self::assertSame(AgentOperationFailure::INVALID_REQUEST, $agentOperationRejectedException->getReason());
            self::assertNull($agentOperationRejectedException->getPrevious());
            self::assertStringNotContainsString('/secret/key', (string) $agentOperationRejectedException);
        }
    }

    public function test_expired_rewrap_early_cleanup_and_transient_terminalization_reject(): void
    {
        $env = new DeliveryEnvironment();
        $operation = $env->operation();
        $material = new AgentDeliveryMaterial(EncryptedCredentialMaterial::fromString('test-copy'), 'test-key-v2');
        foreach (['expired rewrap', 'early cleanup', 'transient terminalization'] as $case) {
            try {
                match ($case) {
                    'expired rewrap' => $operation->rewrapMaterial($material, $env->clock->now()->modify('+1 day')),
                    'early cleanup' => $operation->confirmCleanup($env->clock->now(), new AgentMaintenancePolicy()),
                    default => $operation->failMaterial(AgentDeliveryFailure::TEMPORARY)
                };
                self::fail('Invalid transition accepted.');
            } catch (AgentOperationRejectedException) {
                self::assertNotNull($operation->getMaterial());
            }
        }
    }

    public function test_cleanup_retains_terminal_history_through_later_credential_retirement(): void
    {
        $env = new DeliveryEnvironment();
        $original = $env->operation();
        $now = $env->clock->now()->modify('+2 days');
        $cleaned = $original->expireMaterial($now)->confirmCleanup($now, new AgentMaintenancePolicy());
        self::assertTrue($cleaned->isSinkCleaned());
        $agent = $env->provisioning->agents->all()[0];
        $revoked = $cleaned->retireCredential($agent, $agent->revoke($now));
        self::assertTrue($revoked->isSinkCleaned());
        self::assertTrue($revoked->retireMaterial()->isSinkCleaned());
        self::assertSame(AgentCredentialDisposition::REVOKED, $revoked->getStatus()->getCredentialDisposition());
        self::assertSame(AgentDeliveryDisposition::EXPIRED, $revoked->getStatus()->getDeliveryDisposition());
        self::assertSame($original->getIssuance(), $revoked->resolve($env->provisioning->request));
        self::assertFalse($revoked->canCleanup($now, new AgentMaintenancePolicy()));
    }

    private function query(): ListAgentDeliveryMaintenance
    {
        return new ListAgentDeliveryMaintenance(
            new AgentOperationScope('test', 'user', 'maintainer'),
            new AgentCredentialDestination(AgentDestinationId::generate(), 1)
        );
    }
}
