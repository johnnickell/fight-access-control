<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

/**
 * Captures one immutable permission entry in an authenticated-principal snapshot.
 */
final readonly class PrincipalPermission
{
    /**
     * Creates a permission snapshot from its stable aggregate identity and canonical name.
     */
    public function __construct(
        private PermissionId $id,
        private PermissionName $name
    ) {
    }

    /**
     * Returns the stable permission aggregate identifier.
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
