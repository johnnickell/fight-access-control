<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Role;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
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

        $role = Role::define($id, $name, [$permissionId], new DateTimeImmutable('2026-01-01T00:00:00+00:00'));

        self::assertSame($id, $role->getId());
        self::assertSame($name, $role->getName());
        self::assertSame([$permissionId], $role->getPermissionIds());
        self::assertTrue($role->hasPermission($permissionId));
        self::assertFalse($role->hasPermission(PermissionId::generate()));
        self::assertInstanceOf(DateTimeImmutable::class, $role->getCreatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $role->getCreatedAt());
        self::assertInstanceOf(DateTimeImmutable::class, $role->getUpdatedAt());
        self::assertEquals(new DateTimeImmutable('2026-01-01T00:00:00+00:00'), $role->getUpdatedAt());
    }

    public function test_permission_membership_deduplicates_value_equal_identifiers(): void
    {
        $permissionId = PermissionId::generate();
        $equalPermissionId = PermissionId::fromString($permissionId->toString());

        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [$permissionId, $equalPermissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertCount(1, $role->getPermissionIds());
        self::assertTrue($role->getPermissionIds()[0]->equals($permissionId));
        self::assertTrue($role->hasPermission($equalPermissionId));
    }

    public function test_returned_permission_arrays_cannot_mutate_role_state(): void
    {
        $permissionId = PermissionId::generate();
        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [$permissionId],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $permissionIds = $role->getPermissionIds();
        $permissionIds[] = PermissionId::generate();
        unset($permissionIds[0]);

        self::assertSame([$permissionId], $role->getPermissionIds());
    }

    public function test_it_is_extensible_through_its_factory(): void
    {
        $role = ExtensibleRole::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_EDITOR'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        self::assertInstanceOf(ExtensibleRole::class, $role);
    }

    public function test_it_reconciles_only_managed_roles(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $permissionId = PermissionId::generate();
        $managed = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_OLD'),
            [],
            $createdAt
        );
        $updatedAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $replacement = $managed->reconcileManaged(
            RoleName::fromString('ROLE_NEW'),
            [$permissionId],
            $updatedAt
        );

        self::assertTrue($replacement->isManaged());
        self::assertTrue($replacement->hasPermission($permissionId));
        self::assertSame('ROLE_NEW', $replacement->getName()->toString());
        self::assertSame($createdAt, $replacement->getCreatedAt());
        self::assertSame($updatedAt, $replacement->getUpdatedAt());

        $this->expectException(ManagedRoleException::class);
        Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_CUSTOM'),
            [],
            $createdAt
        )->reconcileManaged(
            RoleName::fromString('ROLE_CLAIMED'),
            [],
            $updatedAt
        );
    }

    public function test_it_renames_only_custom_roles_while_preserving_identity_membership_and_creation(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $updatedAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $permissionId = PermissionId::generate();
        $custom = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_OLD'),
            [$permissionId],
            $createdAt
        );

        $replacement = $custom->renameCustom(RoleName::fromString('ROLE_NEW'), $updatedAt);

        self::assertSame($custom->getId(), $replacement->getId());
        self::assertFalse($replacement->isManaged());
        self::assertSame([$permissionId], $replacement->getPermissionIds());
        self::assertSame($createdAt, $replacement->getCreatedAt());
        self::assertSame($updatedAt, $replacement->getUpdatedAt());
        self::assertSame('ROLE_NEW', $replacement->getName()->toString());

        $this->expectException(CustomRoleException::class);
        Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_MANAGED'),
            [],
            $createdAt
        )->renameCustom(RoleName::fromString('ROLE_FORBIDDEN'), $updatedAt);
    }

    public function test_it_grants_and_revokes_custom_role_permissions_with_explicit_duplicate_failures(): void
    {
        $createdAt = new DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $grantedAt = new DateTimeImmutable('2026-01-02T00:00:00+00:00');
        $revokedAt = new DateTimeImmutable('2026-01-03T00:00:00+00:00');
        $permissionId = PermissionId::generate();
        $custom = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_SUPPORT'),
            [],
            $createdAt
        );

        $granted = $custom->grantPermissionToCustom($permissionId, $grantedAt);
        $revoked = $granted->revokePermissionFromCustom($permissionId, $revokedAt);

        self::assertTrue($granted->hasPermission($permissionId));
        self::assertSame($grantedAt, $granted->getUpdatedAt());
        self::assertFalse($revoked->hasPermission($permissionId));
        self::assertSame($revokedAt, $revoked->getUpdatedAt());

        $this->expectException(CustomRoleException::class);
        $granted->grantPermissionToCustom($permissionId, $grantedAt);
    }

    public function test_revocation_rejects_missing_membership(): void
    {
        $role = Role::define(
            RoleId::generate(),
            RoleName::fromString('ROLE_SUPPORT'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $this->expectException(CustomRoleException::class);
        $role->revokePermissionFromCustom(
            PermissionId::generate(),
            new DateTimeImmutable('2026-01-02T00:00:00+00:00')
        );
    }

    public function test_managed_role_rejects_the_shared_custom_mutation_guard(): void
    {
        $role = Role::defineManaged(
            RoleId::generate(),
            RoleName::fromString('ROLE_MANAGED'),
            [],
            new DateTimeImmutable('2026-01-01T00:00:00+00:00')
        );

        $this->expectException(CustomRoleException::class);
        $role->assertCustom();
    }
}
