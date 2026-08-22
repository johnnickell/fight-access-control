<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Role;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Role::class)]
final class RoleTest extends TestCase
{
    public function test_it_defines_a_role_with_stable_identity_name_and_permissions(): void
    {
        $id = RoleId::generate();
        $name = RoleName::fromString('ROLE_ADMIN');
        $permissionId = PermissionId::generate();

        $role = Role::define($id, $name, [$permissionId]);

        self::assertSame($id, $role->getId());
        self::assertSame($name, $role->getName());
        self::assertSame([$permissionId], $role->getPermissionIds());
        self::assertTrue($role->hasPermission($permissionId));
        self::assertFalse($role->hasPermission(PermissionId::generate()));
    }

    public function test_permission_membership_deduplicates_value_equal_identifiers(): void
    {
        $permissionId = PermissionId::generate();
        $equalPermissionId = PermissionId::fromString($permissionId->toString());

        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [$permissionId, $equalPermissionId]
        );

        self::assertCount(1, $role->getPermissionIds());
        self::assertTrue($role->getPermissionIds()[0]->equals($permissionId));
        self::assertTrue($role->hasPermission($equalPermissionId));
    }

    public function test_returned_permission_arrays_cannot_mutate_role_state(): void
    {
        $permissionId = PermissionId::generate();
        $role = Role::define(RoleId::generate(), RoleName::fromString('ROLE_EDITOR'), [$permissionId]);

        $permissionIds = $role->getPermissionIds();
        $permissionIds[] = PermissionId::generate();
        unset($permissionIds[0]);

        self::assertSame([$permissionId], $role->getPermissionIds());
    }

    public function test_it_is_extensible_through_its_factory(): void
    {
        $role = ExtensibleRole::define(RoleId::generate(), RoleName::fromString('ROLE_EDITOR'), []);

        self::assertInstanceOf(ExtensibleRole::class, $role);
    }
}
