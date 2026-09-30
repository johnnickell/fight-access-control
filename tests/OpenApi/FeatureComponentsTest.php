<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use Fight\AccessControl\Domain\AccessControl\Feature\Command\ProvisionFeatures;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationIssue;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationProblem;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\FeaturePreparationResult;
use Fight\AccessControl\Domain\AccessControl\Feature\Query\ValidateFeaturePreparation;
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

    public function test_preparation_query_and_result_schemas_match_safe_public_values(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        self::assertSame([], new ValidateFeaturePreparation()->toArray());
        self::assertSame('object', $schemas['Fight.AccessControl.ValidateFeaturePreparation']['type']);
        self::assertSame([], $schemas['Fight.AccessControl.ValidateFeaturePreparation']['properties'] ?? []);

        $result = new FeaturePreparationResult(new FeaturePreparationIssue(
            FeatureName::fromString('dashboard'),
            FeaturePreparationProblem::BROKEN_BINDING
        ));
        $schema = $schemas['Fight.AccessControl.FeaturePreparationResult'];
        self::assertSame(array_keys($result->toArray()), $schema['required']);
        self::assertSame(array_keys($result->toArray()), array_keys($schema['properties']));
        self::assertSame('boolean', $schema['properties']['prepared']['type']);
        self::assertSame('array', $schema['properties']['issues']['type']);
        self::assertSame(
            '#/components/schemas/Fight.AccessControl.FeaturePreparationIssue',
            $schema['properties']['issues']['items']['$ref']
        );
        $issue = $schemas['Fight.AccessControl.FeaturePreparationIssue'];
        self::assertSame(array_keys($result->toArray()['issues'][0]), $issue['required']);
        self::assertSame(array_keys($result->toArray()['issues'][0]), array_keys($issue['properties']));
        self::assertSame(array_column(FeaturePreparationProblem::cases(), 'value'), $issue['properties']['problem']['enum']);
    }
}
