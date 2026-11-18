<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskUpdateScope
 */
interface AgentTaskUpdateScope
{
    /**
     * Returns independently trusted producer and operation scope before concealed lookup on every attempt
     *
     * Bind the authenticated producer to the selected operation using consumer-owned authority, not the supplied
     * producer string or creator identity. Throw on unavailable policy. This does not authorize caller continuation.
     */
    public function allowsUpdate(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $producer
    ): bool;
}
