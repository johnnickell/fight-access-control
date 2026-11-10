<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskRemovalScope
 */
interface AgentTaskRemovalScope
{
    /**
     * Returns current consumer removal scope including eligibility and holds for each selected identity
     *
     * Resolve authoritative policy independently of creation, producer, read or cancellation grants and handle
     * possession. Common calls this before existence or deletion, including repeats and missing targets. Throw on
     * unavailable policy; Common removal maps admission faults to its fixed TaskRejected, never successful absence.
     * Checking must not mutate business state or start a nested Task operation under Common's storage lock.
     */
    public function allowsRemoval(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id): bool;
}
