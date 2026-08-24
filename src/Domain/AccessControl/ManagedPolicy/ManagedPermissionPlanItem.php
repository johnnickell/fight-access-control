<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ManagedPolicy;

/**
 * Describes one deterministic managed-permission plan item.
 */
final readonly class ManagedPermissionPlanItem
{
    /**
     * Constructs a managed-permission plan item.
     */
    public function __construct(
        private ManagedPermissionDefinition $definition,
        private ManagedPolicyChangeAction $action
    ) {
    }

    /**
     * Returns the desired managed permission definition.
     */
    public function getDefinition(): ManagedPermissionDefinition
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
