<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Domain\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\AuthenticatedPrincipalException;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalRole;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AuthenticatedUserPrincipal::class)]
#[CoversClass(AuthenticatedPrincipalException::class)]
#[CoversClass(PrincipalPermission::class)]
#[CoversClass(PrincipalRole::class)]
final class AuthenticatedPrincipalTest extends TestCase
{
    public function test_it_preserves_a_typed_immutable_deduplicated_user_authorization_snapshot(): void
    {
        $userId = UserId::generate();
        $refreshSessionId = RefreshSessionId::generate();
        $roleId = RoleId::generate();
        $permissionId = PermissionId::generate();
        $role = new PrincipalRole($roleId, RoleName::fromString('ROLE_EDITOR'));
        $permission = new PrincipalPermission($permissionId, PermissionName::fromString('PUBLISH_ARTICLE'));
        $principal = new AuthenticatedUserPrincipal(
            $userId,
            $refreshSessionId,
            7,
            [$role, new PrincipalRole(RoleId::fromString($roleId->toString()), $role->getName())],
            [
                $permission,
                new PrincipalPermission(PermissionId::fromString($permissionId->toString()), $permission->getName()),
            ]
        );

        $roles = $principal->getRoles();
        $permissions = $principal->getPermissions();
        $roles[] = new PrincipalRole(RoleId::generate(), RoleName::fromString('ROLE_OTHER'));
        $permissions = [];

        self::assertSame($userId, $principal->getUserId());
        self::assertSame($refreshSessionId, $principal->getRefreshSessionId());
        self::assertSame(7, $principal->getAuthenticationVersion());
        self::assertSame([$role], $principal->getRoles());
        self::assertSame([$permission], $principal->getPermissions());
        self::assertSame($roleId, $role->getId());
        self::assertSame($permissionId, $permission->getId());
        self::assertInstanceOf(AuthenticatedAuthority::class, $principal);
        self::assertTrue($principal->hasRole(RoleName::fromString('ROLE_EDITOR')));
        self::assertFalse($principal->hasRole(RoleName::fromString('ROLE_OTHER')));
        self::assertTrue($principal->hasPermission(PermissionName::fromString('PUBLISH_ARTICLE')));
        self::assertFalse($principal->hasPermission(PermissionName::fromString('VIEW_ARTICLE')));
    }

    public function test_it_rejects_a_non_positive_authentication_version(): void
    {
        $this->expectException(AuthenticatedPrincipalException::class);

        new AuthenticatedUserPrincipal(UserId::generate(), RefreshSessionId::generate(), 0, [], []);
    }

    public function test_it_rejects_untyped_role_snapshots(): void
    {
        $this->expectException(AuthenticatedPrincipalException::class);

        new AuthenticatedUserPrincipal(UserId::generate(), RefreshSessionId::generate(), 1, ['ROLE_EDITOR'], []);
    }

    public function test_it_rejects_untyped_permission_snapshots(): void
    {
        $this->expectException(AuthenticatedPrincipalException::class);

        new AuthenticatedUserPrincipal(UserId::generate(), RefreshSessionId::generate(), 1, [], ['VIEW_ARTICLE']);
    }

    public function test_the_legacy_authenticated_principal_snapshot_type_is_not_available(): void
    {
        self::assertFalse(class_exists(
            'Fight\\AccessControl\\Domain\\AccessControl\\Authorization\\AuthenticatedPrincipal'
        ));
    }
}
