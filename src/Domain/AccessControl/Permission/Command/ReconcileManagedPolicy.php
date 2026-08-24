<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Command;

use Fight\AccessControl\Domain\AccessControl\Permission\ManagedPermissionDefinition;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\Query\PreviewManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Role\ManagedRoleDefinition;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Requests atomic reconciliation of version-controlled authorization policy.
 */
final readonly class ReconcileManagedPolicy implements Command
{
    private PreviewManagedPolicy $policy;

    /**
     * @phpstan-param list<ManagedPermissionDefinition> $permissions
     * @phpstan-param list<ManagedRoleDefinition> $roles
     * @phpstan-param list<PermissionId> $referencedPermissionIds
     */
    public function __construct(array $permissions, array $roles, array $referencedPermissionIds)
    {
        $this->policy = new PreviewManagedPolicy($permissions, $roles, $referencedPermissionIds);
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        $policy = PreviewManagedPolicy::fromArray($data);

        return new static(
            $policy->getPermissions(),
            $policy->getRoles(),
            $policy->getReferencedPermissionIds()
        );
    }

    /** @return list<ManagedPermissionDefinition> */
    public function getPermissions(): array
    {
        return $this->policy->getPermissions();
    }

    /** @return list<ManagedRoleDefinition> */
    public function getRoles(): array
    {
        return $this->policy->getRoles();
    }

    /** @return list<PermissionId> */
    public function getReferencedPermissionIds(): array
    {
        return $this->policy->getReferencedPermissionIds();
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return $this->policy->toArray();
    }
}
