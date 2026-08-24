<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Query;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionTier;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Provides a safe immutable view of a permission.
 */
final readonly class PermissionView implements Arrayable
{
    /**
     * Constructs the safe permission view.
     */
    public function __construct(
        private PermissionId $permissionId,
        private PermissionName $name,
        private ?PermissionTier $tier,
        private bool $managed
    ) {
    }

    /**
     * Creates a safe view without exposing aggregate or persistence authority.
     */
    public static function fromPermission(Permission $permission): self
    {
        return new self(
            $permission->getId(),
            $permission->getName(),
            $permission->getTier(),
            $permission->isManaged()
        );
    }

    /**
     * Returns the stable permission identifier.
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the canonical permission name.
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }

    /**
     * Returns the managed permission tier, or null for a custom permission.
     */
    public function getTier(): ?PermissionTier
    {
        return $this->tier;
    }

    /**
     * Returns whether version-controlled policy owns this permission.
     */
    public function isManaged(): bool
    {
        return $this->managed;
    }

    /**
     * Returns the canonical safe array representation.
     *
     * @return array{
     *     permission_id: string,
     *     name: string,
     *     tier: string|null,
     *     managed: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'permission_id' => $this->permissionId->toString(),
            'name' => $this->name->toString(),
            'tier' => $this->tier?->value,
            'managed' => $this->managed,
        ];
    }
}
