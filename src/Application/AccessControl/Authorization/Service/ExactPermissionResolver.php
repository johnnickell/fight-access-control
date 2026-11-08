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
     * Requested identities form a list without duplicates. The repository must return a complete list of distinct
     * IDs and canonical names; order may differ. Violations are internal faults, never ordinary permission denial.
     * Storage failures propagate unchanged. These snapshots grant no authority or synchronization by themselves.
     *
     * @phpstan-param list<PermissionId> $requestedIds
     *
     * @return list<Permission>
     *
     * @throws ExactPermissionResolutionException When the definitions are incomplete or mismatched
     */
    public function resolveDefinitions(array $requestedIds): array
    {
        $this->validateRequestedIds($requestedIds);
        $permissions = $this->permissionRepository->getByIds($requestedIds);
        if (count($requestedIds) !== count($permissions)) {
            $this->reject();
        }

        $permissionsById = $this->indexDefinitions($permissions);
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
     * Validates the erased requested identity set without silently deduplicating it
     *
     * @phpstan-param array<array-key, mixed> $requestedIds
     */
    private function validateRequestedIds(array $requestedIds): void
    {
        if (!array_is_list($requestedIds)) {
            $this->reject();
        }

        $seen = [];
        foreach ($requestedIds as $requestedId) {
            if (!$requestedId instanceof PermissionId || isset($seen[$requestedId->toString()])) {
                $this->reject();
            }

            $seen[$requestedId->toString()] = true;
        }
    }

    /**
     * Validates and indexes the erased repository collection at its owning boundary
     *
     * @phpstan-param array<array-key, mixed> $permissions
     * @return array<string, Permission>
     */
    private function indexDefinitions(array $permissions): array
    {
        if (!array_is_list($permissions)) {
            $this->reject();
        }

        $permissionsById = [];
        $permissionNames = [];
        foreach ($permissions as $permission) {
            if (!$permission instanceof Permission) {
                $this->reject();
            }

            $permissionKey = $permission->getId()->toString();
            $permissionName = $permission->getName()->toString();
            if (isset($permissionsById[$permissionKey]) || isset($permissionNames[$permissionName])) {
                $this->reject();
            }

            $permissionsById[$permissionKey] = $permission;
            $permissionNames[$permissionName] = true;
        }

        return $permissionsById;
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
