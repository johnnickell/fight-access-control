<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission;

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
        private readonly PermissionName $name
    ) {
    }

    /**
     * Defines a permission.
     */
    public static function define(PermissionId $id, PermissionName $name): static
    {
        return new static($id, $name);
    }

    /**
     * Returns the stable permission identifier.
     */
    public function getId(): PermissionId
    {
        return $this->id;
    }

    /**
     * Returns the canonical permission name.
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }
}
