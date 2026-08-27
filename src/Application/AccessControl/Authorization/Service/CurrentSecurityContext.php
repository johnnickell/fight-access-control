<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedAuthority;
use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\CurrentSecurityContextException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;

/**
 * Provides one consumer-selected authenticated authority for a request.
 *
 * Consumers must compose exactly one authority for each request.
 */
final readonly class CurrentSecurityContext
{
    private AuthenticatedAuthority $authenticatedAuthority;

    /**
     * Creates a request-scoped security context from one selected authenticated authority.
     */
    public function __construct(AuthenticatedAuthority ...$authenticatedAuthorities)
    {
        if (count($authenticatedAuthorities) !== 1) {
            throw new CurrentSecurityContextException(
                'The current security context must contain exactly one authenticated authority.'
            );
        }

        $this->authenticatedAuthority = $authenticatedAuthorities[0];
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
