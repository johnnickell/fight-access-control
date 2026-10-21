<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\OpenApi;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RemoveCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Command\RenameCustomRole;
use Fight\AccessControl\Domain\AccessControl\Role\Query\RoleView;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleRevision;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use OpenApi\Generator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class RoleRevisionComponentsTest extends TestCase
{
    public function test_generated_role_views_and_required_commands_share_the_validated_revision_schema(): void
    {
        require_once dirname(__DIR__, 2).'/openapi/bootstrap.php';
        require_once __DIR__.'/CredentialDeliveryConsumerDocument.php';
        $document = new Generator()->generate([
            dirname(__DIR__, 2).'/openapi',
            __DIR__.'/CredentialDeliveryConsumerDocument.php'
        ], null, false);
        self::assertNotNull($document);
        $schemas = json_decode($document->toJson(), true, flags: JSON_THROW_ON_ERROR)['components']['schemas'];
        $revisionSchema = $schemas['Fight.AccessControl.RoleRevision'];
        self::assertSame('string', $revisionSchema['type']);
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_A'), [], new DateTimeImmutable('2030-01-01Z'));
        $messages = [
            'Role'             => RoleView::fromRole($role),
            'RenameCustomRole' => new RenameCustomRole(UserId::generate(), $role->getId(), RoleName::fromString('ROLE_B'), $role->getRevision()),
            'RemoveCustomRole' => new RemoveCustomRole(UserId::generate(), $role->getId(), $role->getRevision())
        ];
        foreach ($messages as $name => $message) {
            $schema = $schemas['Fight.AccessControl.'.$name];
            $payload = $message->toArray();
            self::assertEqualsCanonicalizing(array_keys($payload), $schema['required']);
            self::assertEqualsCanonicalizing(array_keys($payload), array_keys($schema['properties']));
            $key = $name === 'Role' ? 'revision' : 'expected_revision';
            self::assertSame('#/components/schemas/Fight.AccessControl.RoleRevision', $schema['properties'][$key]['$ref']);
            self::assertSame($role->getRevision()->toString(), $payload[$key]);
            self::assertSame(1, preg_match('~'.$revisionSchema['pattern'].'~', $payload[$key]));
        }

        $prefix = '11111111-1111-4111-8111-111111111111:';
        foreach ([$prefix.'1', $prefix.RoleRevision::MAX_SEQUENCE] as $valid) {
            self::assertSame($valid, RoleRevision::fromString($valid)->toString());
            self::assertSame(1, preg_match('~'.$revisionSchema['pattern'].'~', $valid));
            self::assertGreaterThanOrEqual($revisionSchema['minLength'], strlen($valid));
            self::assertLessThanOrEqual($revisionSchema['maxLength'], strlen($valid));
        }

        foreach (['', $prefix.'0', $prefix.'01', $prefix.'-1', $prefix.'1000000000', "{$prefix}1\n", 'AAAAAAAA-1111-4111-8111-111111111111:1', '11111111-1111-7111-8111-111111111111:1'] as $invalid) {
            self::assertSame(0, preg_match('~'.$revisionSchema['pattern'].'~', $invalid));
        }

        self::assertSame('#/components/schemas/Fight.AccessControl.Role', $schemas['Fight.AccessControl.RoleCollection']['properties']['records']['items']['$ref']);
        self::assertSame('#/components/schemas/Fight.AccessControl.Role', $schemas['Fight.AccessControl.JSend.Success.Role']['properties']['data']['$ref']);
    }
}
