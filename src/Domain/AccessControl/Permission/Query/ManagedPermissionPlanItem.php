<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Query;

use Fight\AccessControl\Domain\AccessControl\Permission\ManagedPermissionDefinition;

/**
 * Describes one deterministic managed-permission preview item.
 */
final readonly class ManagedPermissionPlanItem
{
    /**
     * Constructs a managed-permission preview item.
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
