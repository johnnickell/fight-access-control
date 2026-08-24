<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Query;

use Fight\AccessControl\Domain\AccessControl\Role\ManagedRoleDefinition;

/**
 * Describes one deterministic managed-role preview item.
 */
final readonly class ManagedRolePlanItem
{
    /**
     * Constructs a managed-role preview item.
     */
    public function __construct(
        private ManagedRoleDefinition $definition,
        private ManagedPolicyChangeAction $action
    ) {
    }

    /**
     * Returns the desired managed role definition.
     */
    public function getDefinition(): ManagedRoleDefinition
    {
        return $this->definition;
    }

    /**
     * Returns the required reconciliation action.
     */
    public function getAction(): ManagedPolicyChangeAction
    {
        return $this->action;
    }
}
