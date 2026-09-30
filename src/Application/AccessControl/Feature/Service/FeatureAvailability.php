<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\AuthenticatedUserPrincipal;
use Fight\AccessControl\Domain\AccessControl\Authorization\PrincipalPermission;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureBindingException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureNotFoundException;
use Fight\AccessControl\Domain\AccessControl\Feature\Exception\FeatureStateException;
use Fight\AccessControl\Domain\AccessControl\Feature\Feature;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureName;
use Fight\AccessControl\Domain\AccessControl\Feature\FeatureRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionRepository;

/**
 * Class FeatureAvailability
 *
 * Evaluates a current Feature restriction against an existing authenticated snapshot or an anonymous caller.
 */
final readonly class FeatureAvailability
{
    /**
     * Constructs FeatureAvailability
     */
    public function __construct(
        private FeatureRepository $features,
        private PermissionRepository $permissions
    ) {
    }

    /**
     * Returns whether the Feature restriction permits the supplied principal
     *
     * The result never authorizes the underlying action. Repository reads must bypass stale identity-map and
     * negative-lookup caches on every call; operational failures propagate rather than granting availability.
     */
    public function isAvailable(
        FeatureName $name,
        AuthenticatedUserPrincipal|AuthenticatedAgentPrincipal|null $principal
    ): bool {
        $feature = $this->features->getByName($name);
        if (!$feature instanceof Feature) {
            throw new FeatureNotFoundException('The Feature is not registered.');
        }

        if (!$feature->getName()->equals($name)) {
            throw new FeatureStateException('The stored Feature does not match the requested name.');
        }

        $permissionId = $feature->getPermissionId();
        $permission = $this->permissions->getById($permissionId);
        if (!$permission instanceof Permission) {
            throw new FeatureBindingException('The Feature testing Permission is missing.');
        }

        if (!$permission->getId()->equals($permissionId)) {
            throw new FeatureStateException('The stored Permission does not match the Feature binding.');
        }

        $hasPermission = $principal !== null && array_any(
            $principal->getPermissions(),
            static fn(PrincipalPermission $captured): bool => $captured->getPermissionId()->equals($permissionId)
        );

        return $feature->getStatus()->isAvailable($hasPermission);
    }
}
