<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

/**
 * Describes one deterministic managed-role plan item.
 */
final readonly class ManagedRolePlanItem
{
    /**
     * Constructs a managed-role plan item.
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
