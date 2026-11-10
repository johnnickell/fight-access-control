<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskDefinition;

/**
 * Interface AgentTaskCreationScope
 */
interface AgentTaskCreationScope
{
    /**
     * Returns authoritative creation scope for the exact original operation including repeat attempts
     *
     * Definitions and requested producer identities are selectors, not evidence of ownership or authority.
     * Throw on unavailable policy; never return allow from unverified selectors or grant later read/update rights.
     */
    public function allowsCreation(AuthenticatedAgentPrincipal $principal, TaskDefinition $definition): bool;
}
