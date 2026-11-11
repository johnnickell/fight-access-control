<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskCancellationScope
 */
interface AgentTaskCancellationScope
{
    /**
     * Returns independent current scope for requesting cooperative cancellation intent
     *
     * Bind the trusted requester, Task and original operation, including repeats, terminal and missing targets.
     * Throw on unavailable policy. This decision grants neither intent observation nor worker control.
     */
    public function allowsCancellation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool;
}
