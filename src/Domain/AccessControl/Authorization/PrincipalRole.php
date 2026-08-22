<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization;

use Fight\AccessControl\Domain\AccessControl\Role\RoleId;
use Fight\AccessControl\Domain\AccessControl\Role\RoleName;

/**
 * Captures one immutable role entry in an authenticated-principal snapshot.
 */
final readonly class PrincipalRole
{
    /**
     * Creates a role snapshot from its stable aggregate identity and canonical name.
     */
    public function __construct(
        private RoleId $id,
        private RoleName $name
    ) {
    }

    /**
     * Returns the stable role aggregate identifier.
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
}
