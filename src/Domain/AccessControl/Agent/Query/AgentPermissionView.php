<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Query;

use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Provides the narrow immutable Permission identity/name snapshot for an Agent read.
 */
final readonly class AgentPermissionView implements Arrayable
{
    /**
     * Creates the safe Agent Permission snapshot.
     */
    public function __construct(
        private PermissionId $permissionId,
        private PermissionName $name
    ) {
    }

    /**
     * Creates the narrow snapshot from an authoritative Permission.
     */
    public static function fromPermission(Permission $permission): self
    {
        return new self($permission->getId(), $permission->getName());
    }

    /**
     * Returns the stable Permission identifier.
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
    }

    /**
     * Returns the canonical Permission name.
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }

    /**
     * Returns the exact safe array representation.
     *
     * @return array{permission_id: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'permission_id' => $this->permissionId->toString(),
            'name' => $this->name->toString(),
        ];
    }
}
