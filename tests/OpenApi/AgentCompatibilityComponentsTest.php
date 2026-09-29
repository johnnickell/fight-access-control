<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentName;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentState;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentView;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AgentCompatibilityComponentsTest extends TestCase
{
    public function test_generated_agent_schema_requires_explicit_safe_correlation_marker(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/AgentDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/AgentDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        $schema = $schemas['Fight.AccessControl.Agent'];
        $at = new DateTimeImmutable('2026-01-01T00:00:00Z');
        foreach ([false, true] as $recoverable) {
            $view = AgentView::fromAgent(Agent::reconstitute(
                AgentId::generate(),
                AgentName::fromString('Existing deployment'),
                AgentState::ACTIVE,
                AgentCredentialId::generate(),
                7,
                'private-envelope',
                [],
                12,
                $at,
                $at,
                $recoverable
            ), []);
            self::assertEqualsCanonicalizing(array_keys($view->toArray()), $schema['required']);
            self::assertEqualsCanonicalizing(array_keys($view->toArray()), array_keys($schema['properties']));
            self::assertSame($recoverable, $view->toArray()['recoverable_credential_operation']);
            self::assertStringNotContainsString('private-envelope', serialize($view));
        }

        self::assertSame('boolean', $schema['properties']['recoverable_credential_operation']['type']);
        self::assertArrayNotHasKey('default', $schema['properties']['recoverable_credential_operation']);
        self::assertSame(
            '#/components/schemas/Fight.AccessControl.Agent',
            $schemas['Fight.AccessControl.AgentCollection']['properties']['records']['items']['$ref']
        );
        self::assertSame(
            '#/components/schemas/Fight.AccessControl.Agent',
            $schemas['Fight.AccessControl.JSend.Success.Agent']['properties']['data']['$ref']
        );
    }
}
