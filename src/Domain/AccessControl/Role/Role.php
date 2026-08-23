<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Collection\HashSet;

/**
 * Represents a role and its permission membership.
 *
 * @phpstan-consistent-constructor
 */
class Role
{
    /** @var HashSet<PermissionId> */
    private readonly HashSet $permissionIds;

    /**
     * Creates a role definition.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    protected function __construct(
        private readonly RoleId $id,
        private readonly RoleName $name,
        array $permissionIds
    ) {
        $this->permissionIds = HashSet::of(PermissionId::class);

        foreach ($permissionIds as $permissionId) {
            $this->permissionIds->add($permissionId);
        }
    }

    /**
     * Defines a role.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public static function define(RoleId $id, RoleName $name, array $permissionIds): static
    {
        return new static($id, $name, $permissionIds);
    }

    /**
     * Returns the stable role identifier.
     */
    public function getId(): RoleId
    {
        return $this->id;
    }

    /**
     * Returns the canonical role name.
     */
    public function getName(): RoleName
    {
        return $this->name;
    }

    /**
     * Returns an immutable snapshot of permission membership.
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds->toArray();
    }

    /**
     * Determines whether the role contains a permission.
     */
    public function hasPermission(PermissionId $permissionId): bool
    {
        return $this->permissionIds->contains($permissionId);
    }
}
