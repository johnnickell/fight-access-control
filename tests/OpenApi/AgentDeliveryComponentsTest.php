<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentMaintenanceResult;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryDisposition;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDeliveryId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentDestinationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\CountAgentDeliveryKeyReferences;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListAgentDeliveryMaintenance;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\ListDueAgentDeliveries;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AgentDeliveryComponentsTest extends TestCase
{
    public function test_generated_discovery_schemas_match_real_safe_payloads_and_references(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/AgentDeliveryConsumerDocument.php';
        // This consumer root deliberately has no paths: verify component composition without inventing an endpoint.
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/AgentDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, 512, JSON_THROW_ON_ERROR)['components']['schemas'];
        self::assertArrayHasKey('Consumer.AgentRecovery', $schemas);
        $key = new AgentOperationKey(new AgentOperationScope('consumer', 'user', 'owner'), AgentOperationId::generate());
        $destination = new AgentCredentialDestination(AgentDestinationId::generate(), 1);
        $query = new ListDueAgentDeliveries($key->getScope(), $destination);
        $maintenance = new ListAgentDeliveryMaintenance($key->getScope(), $destination);
        $accounting = new CountAgentDeliveryKeyReferences(new AgentDeliveryKeyVersion('wrapping-v2'));
        $issuance = new AgentIssuance(
            $key,
            AgentDeliveryId::generate(),
            AgentId::generate(),
            AgentCredentialId::generate(),
            0,
            $destination,
            1,
            new DateTimeImmutable('2026-09-27T12:00:00Z')
        );
        $view = AgentOperationView::confirmed(
            2,
            $issuance,
            AgentDeliveryDisposition::PENDING,
            AgentCredentialDisposition::CURRENT
        );
        foreach (
            [
            'ListAgentDeliveryMaintenance'    => $maintenance->toArray(),
            'CountAgentDeliveryKeyReferences' => $accounting->toArray(),
            'ListDueAgentDeliveries'          => $query->toArray(),
            'AgentOperationKey'               => $key->toArray(),
            'AgentIssuance'                   => $issuance->toArray(),
            'AgentOperation.Confirmed'        => $view->toArray(),
            'AgentOperation.Indeterminate'    => AgentOperationView::indeterminate($key)->toArray()
            ] as $name => $payload
        ) {
            $schema = $schemas['Fight.AccessControl.'.$name];
            self::assertEqualsCanonicalizing(array_keys($payload), $schema['required'], $name);
            self::assertEqualsCanonicalizing(array_keys($payload), array_keys($schema['properties']), $name);
        }

        $maintenanceSchema = $schemas['Fight.AccessControl.ListAgentDeliveryMaintenance']['properties'];
        self::assertSame(array_column(AgentMaintenanceWork::cases(), 'value'), $maintenanceSchema['work']['enum']);
        self::assertSame(['string', 'null'], $maintenanceSchema['after']['type']);
        foreach ($maintenance->getPolicy()->toArray() as $setting => $default) {
            self::assertSame($default, $maintenanceSchema[$setting]['default']);
            self::assertSame(1, $maintenanceSchema[$setting]['minimum']);
        }

        self::assertSame(100, $maintenanceSchema['batch_size']['maximum']);
        self::assertSame(604800, $maintenanceSchema['cleanup_grace_seconds']['maximum']);
        self::assertSame(
            array_column(AgentMaintenanceResult::cases(), 'value'),
            $schemas['Fight.AccessControl.AgentMaintenanceResult']['enum']
        );
        self::assertSame('integer', $schemas['Fight.AccessControl.AgentDeliveryKeyReferences']['type']);
        self::assertSame(0, $schemas['Fight.AccessControl.AgentDeliveryKeyReferences']['minimum']);
        self::assertSame(100, $schemas['Fight.AccessControl.AgentDeliveryMaintenance']['maxItems']);
        self::assertSame(
            '#/components/schemas/Fight.AccessControl.AgentOperation.Confirmed',
            $schemas['Fight.AccessControl.AgentDeliveryMaintenance']['items']['$ref']
        );
        $limit = $schemas['Fight.AccessControl.ListDueAgentDeliveries']['properties']['limit'];
        self::assertSame('integer', $limit['type']);
        self::assertSame(1, $limit['minimum']);
        self::assertSame(100, $limit['maximum']);
        self::assertSame($query->getLimit(), $limit['default']);
        $confirmed = $schemas['Fight.AccessControl.AgentOperation.Confirmed']['properties'];
        self::assertSame(array_column(AgentDeliveryDisposition::cases(), 'value'), $confirmed['delivery_disposition']['enum']);
        self::assertSame(array_column(AgentCredentialDisposition::cases(), 'value'), $confirmed['credential_disposition']['enum']);
        self::assertSame([2], $confirmed['canonical_version']['enum']);
        $retained = AgentOperationView::confirmed(
            2,
            $issuance,
            AgentDeliveryDisposition::RETIRED,
            AgentCredentialDisposition::REVOKED
        );
        $retained->assertReadable($key, $destination);
        self::assertContains($retained->toArray()['canonical_version'], $confirmed['canonical_version']['enum']);
        self::assertNotContains(1, $confirmed['canonical_version']['enum']);
        self::assertNotContains(99, $confirmed['canonical_version']['enum']);
        self::assertSame(['confirmed'], $confirmed['issuance_outcome']['enum']);
        $indeterminate = $schemas['Fight.AccessControl.AgentOperation.Indeterminate']['properties'];
        foreach (['canonical_version', 'issuance', 'delivery_disposition', 'credential_disposition'] as $field) {
            self::assertSame(['null'], $indeterminate[$field]['type']);
        }

        $list = $schemas['Fight.AccessControl.DueAgentDeliveries'];
        self::assertSame('array', $list['type']);
        self::assertSame(100, $list['maxItems']);
        self::assertSame('#/components/schemas/Fight.AccessControl.AgentOperation.Confirmed', $list['items']['$ref']);
        self::assertArrayNotHasKey('properties', $list);
        $envelope = $schemas['Fight.AccessControl.JSend.Success.DueAgentDeliveries']['properties'];
        self::assertSame(['success'], $envelope['status']['enum']);
        self::assertSame('#/components/schemas/Fight.AccessControl.DueAgentDeliveries', $envelope['data']['$ref']);
        array_walk_recursive($schemas, static function (mixed $value, string|int $key) use ($schemas): void {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
                self::assertArrayHasKey(substr($value, strlen('#/components/schemas/')), $schemas);
            }
        });
        foreach (['ciphertext', 'shared_secret', 'key_version', 'claim_token', 'receipt'] as $unsafe) {
            self::assertStringNotContainsString($unsafe, json_encode($confirmed, JSON_THROW_ON_ERROR));
        }
    }
}
