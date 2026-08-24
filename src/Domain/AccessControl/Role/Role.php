<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\CustomRoleException;
use Fight\AccessControl\Domain\AccessControl\Role\Exception\ManagedRoleException;
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
        array $permissionIds,
        private readonly bool $managed,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
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
    public static function define(
        RoleId $id,
        RoleName $name,
        array $permissionIds,
        DateTimeImmutable $createdAt
    ): static {
        return new static($id, $name, $permissionIds, false, $createdAt, $createdAt);
    }

    /**
     * Defines a version-controlled managed role.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public static function defineManaged(
        RoleId $id,
        RoleName $name,
        array $permissionIds,
        DateTimeImmutable $createdAt
    ): static {
        return new static($id, $name, $permissionIds, true, $createdAt, $createdAt);
    }

    /**
     * Reconciles the exact version-controlled role definition.
     *
     * @phpstan-param list<PermissionId> $permissionIds
     */
    public function reconcileManaged(
        RoleName $name,
        array $permissionIds,
        DateTimeImmutable $updatedAt
    ): static {
        if (!$this->managed) {
            throw new ManagedRoleException(
                'A custom role cannot be claimed by managed policy.'
            );
        }

        return new static($this->id, $name, $permissionIds, true, $this->createdAt, $updatedAt);
    }

    /**
     * Renames a runtime-owned custom role.
     */
    public function renameCustom(RoleName $name, DateTimeImmutable $updatedAt): static
    {
        if ($this->managed) {
            throw new CustomRoleException('A managed role cannot be renamed at runtime.');
        }

        return new static(
            $this->id,
            $name,
            $this->permissionIds->toArray(),
            false,
            $this->createdAt,
            $updatedAt
        );
    }

    /**
     * Grants an existing permission to a runtime-owned custom role.
     */
    public function grantPermissionToCustom(PermissionId $permissionId, DateTimeImmutable $updatedAt): static
    {
        $this->assertCustom();

        if ($this->permissionIds->contains($permissionId)) {
            throw new CustomRoleException('The custom role already contains the permission.');
        }

        $permissionIds = $this->permissionIds->toArray();
        $permissionIds[] = $permissionId;

        return new static($this->id, $this->name, $permissionIds, false, $this->createdAt, $updatedAt);
    }

    /**
     * Revokes an existing permission from a runtime-owned custom role.
     */
    public function revokePermissionFromCustom(PermissionId $permissionId, DateTimeImmutable $updatedAt): static
    {
        $this->assertCustom();

        if (!$this->permissionIds->contains($permissionId)) {
            throw new CustomRoleException('The custom role does not contain the permission.');
        }

        $permissionIds = array_values(array_filter(
            $this->permissionIds->toArray(),
            static fn(PermissionId $candidate): bool => !$candidate->equals($permissionId)
        ));

        return new static($this->id, $this->name, $permissionIds, false, $this->createdAt, $updatedAt);
    }

    /**
     * Returns the stable role identifier.
     */
    public function getId(): RoleId
    {
        return $this->id;
    }

    /**
     * Returns the datetime when the role was created.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the datetime when the role was last updated.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
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

    /**
     * Returns whether version-controlled policy owns this role.
     */
    public function isManaged(): bool
    {
        return $this->managed;
    }

    /**
     * Rejects runtime mutation of a managed role.
     */
    public function assertCustom(): void
    {
        if ($this->managed) {
            throw new CustomRoleException('A managed role cannot be mutated at runtime.');
        }
    }
}
