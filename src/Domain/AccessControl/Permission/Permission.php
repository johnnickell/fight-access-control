<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

use DateTimeImmutable;

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
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * Defines a permission.
     */
    public static function define(PermissionId $id, PermissionName $name, DateTimeImmutable $createdAt): static
    {
        return new static($id, $name, $createdAt, $createdAt);
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
}
