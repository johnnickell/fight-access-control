<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;

/**
 * Provides one consumer-selected authenticated authority for a request.
 */
final readonly class SecurityContext
{
    /**
     * Creates a request-scoped security context from one selected authenticated authority.
     */
    public function __construct(private AuthenticatedAuthority $authenticatedAuthority)
    {
    }

    /**
     * Returns the selected immutable authenticated authority.
     */
    public function getAuthenticatedAuthority(): AuthenticatedAuthority
    {
        return $this->authenticatedAuthority;
    }

    /**
     * Determines whether the selected authority contains a permission name.
     */
    public function hasPermission(PermissionName $permissionName): bool
    {
        return $this->authenticatedAuthority->hasPermission($permissionName);
    }

    /**
     * Determines whether the selected authority contains a role name.
     */
    public function hasRole(RoleName $roleName): bool
    {
        return $this->authenticatedAuthority->hasRole($roleName);
    }
}
