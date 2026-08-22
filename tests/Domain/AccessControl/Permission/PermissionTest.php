<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Permission;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Permission::class)]
final class PermissionTest extends TestCase
{
    public function test_it_defines_a_permission_with_stable_identity_and_name(): void
    {
        $id = PermissionId::generate();
        $name = PermissionName::fromString('MANAGE_USERS');

        $permission = Permission::define($id, $name);

        self::assertSame($id, $permission->getId());
        self::assertSame($name, $permission->getName());
    }

    public function test_it_is_extensible_through_its_factory(): void
    {
        $id = PermissionId::generate();
        $name = PermissionName::fromString('VIEW_USERS');
        $permission = ExtensiblePermission::define($id, $name);

        self::assertInstanceOf(ExtensiblePermission::class, $permission);
    }
}
