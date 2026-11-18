<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskCancellationObservationScope
 */
interface AgentTaskCancellationObservationScope
{
    /**
     * Returns independent current scope for protected original-caller cancellation intent observation
     *
     * Bind trusted consumer observer/caller/Task/original operation, not caller-supplied identity or a prior grant.
     * Throw on unavailable policy. Observation neither requests cancellation nor proves stopping or rollback.
     */
    public function allowsCancellationObservation(
        AuthenticatedAgentPrincipal $principal,
        string $scope,
        TaskId $id,
        string $caller
    ): bool;
}
