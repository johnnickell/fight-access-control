<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Authorization\Service;

use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;

/**
 * Resolves authoritative Permission definitions into exact principal snapshots.
 *
 * @internal
 */
final readonly class ExactPermissionResolver
{
    /**
     * Creates the exact Permission resolver.
     */
    public function __construct(private PermissionRepository $permissionRepository)
    {
    }

    /**
     * Returns ordered snapshots only when the authoritative definitions match every requested identity exactly.
     *
     * @phpstan-param list<PermissionId> $requestedIds
     *
     * @return list<PrincipalPermission>
     *
     * @throws ExactPermissionResolutionException When the authoritative definitions are incomplete or mismatched
     */
    public function resolve(array $requestedIds): array
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

        $snapshots = [];
        foreach ($requestedIds as $requestedId) {
            $permissionKey = $requestedId->toString();
            if (!isset($permissionsById[$permissionKey])) {
                $this->reject();
            }

            $snapshots[] = new PrincipalPermission($requestedId, $permissionsById[$permissionKey]->getName());
        }

        return $snapshots;
    }

    /**
     * Rejects incomplete or mismatched authoritative Permission definitions.
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
