<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Captures one immutable direct-Permission entry in an authenticated Agent-principal snapshot.
 */
final readonly class AgentPrincipalPermission implements Arrayable
{
    /**
     * Creates the direct-Permission snapshot from its stable identity and canonical name.
     */
    public function __construct(
        private PermissionId $permissionId,
        private PermissionName $name
    ) {
    }

    /**
     * Returns the canonical Permission name.
     */
    public function getName(): PermissionName
    {
        return $this->name;
    }

    /**
     * Returns the stable Permission identifier.
     */
    public function getPermissionId(): PermissionId
    {
        return $this->permissionId;
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
