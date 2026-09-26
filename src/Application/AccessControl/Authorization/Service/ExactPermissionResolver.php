<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;

/**
 * Class ExactPermissionResolver
 *
 * Resolves authoritative Permission definitions into exact principal snapshots.
 *
 * @internal
 */
final readonly class ExactPermissionResolver
{
    /**
     * Constructs ExactPermissionResolver
     *
     * Creates the exact Permission resolver.
     */
    public function __construct(private PermissionRepository $permissionRepository)
    {
    }

    /**
     * Returns ordered snapshots only when the authoritative definitions match every requested identity exactly
     *
     * @phpstan-param list<PermissionId> $requestedIds
     *
     * @return list<PrincipalPermission>
     *
     * @throws ExactPermissionResolutionException When the authoritative definitions are incomplete or mismatched
     */
    public function resolve(array $requestedIds): array
    {
        $permissions = $this->resolveDefinitions($requestedIds);
        $snapshots = [];
        foreach ($permissions as $permission) {
            $snapshots[] = new PrincipalPermission($permission->getId(), $permission->getName());
        }

        return $snapshots;
    }

    /**
     * Returns exact authoritative Permission definitions in requested order
     *
     * @phpstan-param list<PermissionId> $requestedIds
     *
     * @return list<Permission>
     *
     * @throws ExactPermissionResolutionException When the definitions are incomplete or mismatched
     */
    public function resolveDefinitions(array $requestedIds): array
    {
        $permissions = $this->permissionRepository->getByIds($requestedIds);
        if (count($requestedIds) !== count($permissions)) {
            $this->reject();
        }

        $permissionsById = [];
        foreach ($permissions as $permission) {
            $permissionKey = $permission->getId()->toString();
            if (isset($permissionsById[$permissionKey])) {
                $this->reject();
            }

            $permissionsById[$permissionKey] = $permission;
        }

        $definitions = [];
        foreach ($requestedIds as $requestedId) {
            $permissionKey = $requestedId->toString();
            if (!isset($permissionsById[$permissionKey])) {
                $this->reject();
            }

            $definitions[] = $permissionsById[$permissionKey];
        }

        return $definitions;
    }

    /**
     * Rejects incomplete or mismatched authoritative Permission definitions
     *
     * @throws ExactPermissionResolutionException Always
     */
    private function reject(): never
    {
        throw new ExactPermissionResolutionException(
            'The authoritative Permission definitions do not match the requested identities.'
        );
    }
}
