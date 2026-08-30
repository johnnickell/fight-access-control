<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Captures one immutable permission entry in an authenticated-principal snapshot.
 */
final readonly class PrincipalPermission implements Arrayable
{
    /**
     * Creates a permission snapshot from its stable aggregate identity and canonical name.
     */
    public function __construct(
        private PermissionId $permissionId,
        private PermissionName $name
    ) {
    }

    /**
     * Returns the stable permission aggregate identifier.
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
