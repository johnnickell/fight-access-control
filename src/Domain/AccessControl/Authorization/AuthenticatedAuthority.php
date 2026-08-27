<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;

/**
 * Defines the framework-neutral authorization checks for an immutable authenticated snapshot.
 */
interface AuthenticatedAuthority
{
    /**
     * Determines whether the snapshot contains a permission name.
     */
    public function hasPermission(PermissionName $permissionName): bool;

    /**
     * Determines whether the snapshot contains a role name.
     */
    public function hasRole(RoleName $roleName): bool;
}
