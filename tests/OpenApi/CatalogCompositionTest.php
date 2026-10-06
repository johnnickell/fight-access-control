<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CatalogCompositionTest extends TestCase
{
    public function test_consumer_composition_preserves_authentication_and_administrative_shapes(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, 512, JSON_THROW_ON_ERROR)['components']['schemas'];
        self::assertArrayHasKey('Consumer.CredentialDelivery', $schemas);
        $activate = $schemas['Fight.AccessControl.Authentication.ActivateRequest'];
        self::assertSame(['user_id', 'activation_credential', 'plain_password'], $activate['required']);
        self::assertSame('boolean', $activate['properties']['remember']['type']);
        $login = $schemas['Fight.AccessControl.Authentication.LoginRequest'];
        self::assertSame(['email', 'plain_password'], $login['required']);
        self::assertSame('boolean', $login['properties']['remember']['type']);
        self::assertArrayNotHasKey('remembered', $login['properties']);
        self::assertSame(
            ['pending_activation', 'active', 'disabled', 'deleted'],
            $schemas['Fight.AccessControl.RestoreUser']['properties']['restoration_state']['enum']
        );
        foreach (['PaginationRequest', 'ListActiveSessions'] as $name) {
            $orderings = $schemas['Fight.AccessControl.'.$name]['properties']['orderings'];
            self::assertSame('object', $orderings['type']);
            self::assertSame('string', $orderings['additionalProperties']['type']);
            self::assertSame(['ASC', 'DESC'], $orderings['additionalProperties']['enum']);
        }

        $empty = $schemas['Fight.AccessControl.JSend.Success.Empty']['properties']['data'];
        self::assertSame(['null'], $empty['type']);
        self::assertArrayNotHasKey('nullable', $empty);
        $permission = $schemas['Fight.AccessControl.Permission'];
        self::assertContains('tier', $permission['required']);
        self::assertSame('string', $permission['properties']['tier']['type']);
        self::assertFalse($permission['properties']['tier']['nullable'] ?? false);
        self::assertSame(['ADMIN_SAFE', 'SUPER_ADMIN_ONLY'], $permission['properties']['tier']['enum']);
        $collection = $schemas['Fight.AccessControl.PermissionCollection'];
        self::assertContains('records', $collection['required']);
        self::assertSame('array', $collection['properties']['records']['type']);
        self::assertSame(
            '#/components/schemas/Fight.AccessControl.Permission',
            $collection['properties']['records']['items']['$ref']
        );
        foreach (['Permission', 'PermissionCollection'] as $name) {
            $envelope = $schemas['Fight.AccessControl.JSend.Success.'.$name];
            self::assertSame(['status', 'data'], $envelope['required']);
            self::assertSame('#/components/schemas/Fight.AccessControl.'.$name, $envelope['properties']['data']['$ref']);
        }
    }
}
