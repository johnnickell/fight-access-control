<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Permission\Exception\ManagedPolicyDefinitionException;

/**
 * Represents a stable permission definition.
 *
 * @phpstan-consistent-constructor
 */
class Permission
{
    /**
     * Creates a permission definition.
     */
    protected function __construct(
        private readonly PermissionId $id,
        private readonly PermissionName $name,
        private readonly ?PermissionTier $tier,
        private readonly bool $managed,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * Defines a permission.
     */
    public static function define(PermissionId $id, PermissionName $name, DateTimeImmutable $createdAt): static
    {
        return new static($id, $name, null, false, $createdAt, $createdAt);
    }

    /**
     * Defines a version-controlled managed permission.
     */
    public static function defineManaged(
        PermissionId $id,
        PermissionName $name,
        PermissionTier $tier,
        DateTimeImmutable $createdAt
    ): static {
        return new static($id, $name, $tier, true, $createdAt, $createdAt);
    }

    /**
     * Reconciles the version-controlled name and tier while retaining stable identity and creation time.
     */
    public function reconcileManaged(
        PermissionName $name,
        PermissionTier $tier,
        DateTimeImmutable $updatedAt
    ): static {
        if (!$this->managed) {
            throw new ManagedPolicyDefinitionException(
                'A custom permission cannot be claimed by managed policy.'
            );
        }

        return new static($this->id, $name, $tier, true, $this->createdAt, $updatedAt);
    }

    /**
     * Returns the stable permission identifier.
     */
    public function getId(): PermissionId
    {
        return $this->id;
    }

    /**
     * Returns the datetime when the permission was created.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the datetime when the permission was last updated.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Returns the canonical permission name.
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }

    /**
     * Returns whether version-controlled policy owns this permission.
     */
    public function isManaged(): bool
    {
        return $this->managed;
    }

    /**
     * Returns the managed permission tier, or null for a custom permission.
     */
    public function getTier(): ?PermissionTier
    {
        return $this->tier;
    }

    /**
     * Returns the tier owned by a managed permission.
     */
    public function getManagedTier(): PermissionTier
    {
        if (!$this->tier instanceof PermissionTier) {
            throw new ManagedPolicyDefinitionException('A custom permission has no managed tier.');
        }

        return $this->tier;
    }
}
