<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Authorization\Exception\AuthenticatedPrincipalException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Captures an authenticated identity and its authoritative authorization snapshot.
 */
final readonly class AuthenticatedPrincipal
{
    /** @var list<PrincipalRole> */
    private array $roles;

    /** @var list<PrincipalPermission> */
    private array $permissions;

    /**
     * Constructs an immutable authenticated-principal snapshot.
     *
     * @param UserId            $userId
     * @param RefreshSessionId $refreshSessionId
     * @param integer          $authenticationVersion
     * @param array<mixed>     $roles
     * @param array<mixed>     $permissions
     */
    public function __construct(
        private UserId $userId,
        private RefreshSessionId $refreshSessionId,
        private int $authenticationVersion,
        array $roles,
        array $permissions
    ) {
        if ($authenticationVersion < 1) {
            throw new AuthenticatedPrincipalException('The authentication version must be positive.');
        }

        $uniqueRoles = [];
        foreach ($roles as $role) {
            if (!$role instanceof PrincipalRole) {
                throw new AuthenticatedPrincipalException(
                    'Authenticated principal roles must be PrincipalRole snapshots.'
                );
            }

            $uniqueRoles[$role->getId()->toString()] ??= $role;
        }

        $uniquePermissions = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof PrincipalPermission) {
                throw new AuthenticatedPrincipalException(
                    'Authenticated principal permissions must be PrincipalPermission snapshots.'
                );
            }

            $uniquePermissions[$permission->getId()->toString()] ??= $permission;
        }

        $this->roles = array_values($uniqueRoles);
        $this->permissions = array_values($uniquePermissions);
    }

    /**
     * Returns the authoritative authentication version.
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Returns the permissions captured during authoritative resolution.
     *
     * @return list<PrincipalPermission>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Returns the stable refresh-session identity.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns the roles captured during authoritative resolution.
     *
     * @return list<PrincipalRole>
     */
    public function getRoles(): array
    {
        return $this->roles;
    }

    /**
     * Returns the stable authenticated identity.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Determines whether the snapshot contains a permission name.
     */
    public function hasPermission(PermissionName $permissionName): bool
    {
        return array_any(
            $this->permissions,
            static fn(PrincipalPermission $permission): bool => $permission->getName()->equals($permissionName)
        );
    }

    /**
     * Determines whether the snapshot contains a role name.
     */
    public function hasRole(RoleName $roleName): bool
    {
        return array_any(
            $this->roles,
            static fn(PrincipalRole $role): bool => $role->getName()->equals($roleName)
        );
    }
}
