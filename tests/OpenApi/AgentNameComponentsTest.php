<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentUpdateInitiator;
use Fight\AccessControl\Domain\AccessControl\Agent\Command\UpdateAgent;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedPrincipalType;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AgentNameComponentsTest extends TestCase
{
    public function test_generated_command_and_provenance_shapes_match_current_serialized_messages(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/AgentDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/AgentDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        $commandSchema = $schemas['Fight.AccessControl.UpdateAgent'];
        $initiatorSchema = $schemas['Fight.AccessControl.AgentUpdateInitiator'];
        foreach ([UserId::generate(), AgentId::generate()] as $id) {
            $initiator = new AgentUpdateInitiator($id);
            $command = new UpdateAgent($initiator, AgentId::generate(), '  Profile name  ');
            self::assertEqualsCanonicalizing(array_keys($command->toArray()), $commandSchema['required']);
            self::assertEqualsCanonicalizing(array_keys($command->toArray()), array_keys($commandSchema['properties']));
            self::assertEqualsCanonicalizing(array_keys($initiator->toArray()), $initiatorSchema['required']);
            self::assertEqualsCanonicalizing(array_keys($initiator->toArray()), array_keys($initiatorSchema['properties']));
            self::assertContains($initiator->getType()->value, $initiatorSchema['properties']['type']['enum']);
            self::assertEquals($command, UpdateAgent::fromArray($command->toArray()));
        }

        self::assertSame(
            array_map(static fn($type): string => $type->value, AuthenticatedPrincipalType::cases()),
            $initiatorSchema['properties']['type']['enum']
        );
        self::assertSame(
            '#/components/schemas/Fight.AccessControl.AgentUpdateInitiator',
            $commandSchema['properties']['initiator']['$ref']
        );
        self::assertSame('uuid', $initiatorSchema['properties']['id']['format']);
        self::assertSame('uuid', $commandSchema['properties']['agent_id']['format']);
        self::assertSame('string', $commandSchema['properties']['name']['type']);
        // The raw command input can exceed 120 bytes through trim padding or multibyte characters.
        self::assertArrayNotHasKey('maxLength', $commandSchema['properties']['name']);
    }
}
