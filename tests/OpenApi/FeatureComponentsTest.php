<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class FeatureComponentsTest extends TestCase
{
    public function test_provisioning_schema_preserves_required_nullable_lazy_configuration(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        $schema = $schemas['Fight.AccessControl.ProvisionFeatures'];
        foreach ([null, '', 'bad name', 'TEST_FEATURES'] as $name) {
            $payload = new ProvisionFeatures($name)->toArray();
            self::assertSame(array_keys($payload), $schema['required']);
            self::assertSame(array_keys($payload), array_keys($schema['properties']));
            self::assertSame($payload, ProvisionFeatures::fromArray($payload)->toArray());
        }

        $property = $schema['properties']['default_permission_name'];
        self::assertSame(['string', 'null'], $property['type']);
        self::assertArrayNotHasKey('pattern', $property);
        self::assertArrayNotHasKey('minLength', $property);
        self::assertSame('object', $schema['type']);
    }
}
