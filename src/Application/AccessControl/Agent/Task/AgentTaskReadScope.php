<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Task;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Task\TaskId;

/**
 * Interface AgentTaskReadScope
 */
interface AgentTaskReadScope
{
    /**
     * Returns independent current sharing authority over the complete state and all protected payloads
     *
     * Never manufacture redacted success. Throw on unverifiable scope. Handle possession, Tool availability,
     * creation and producer authority confer no read permission. This check must not mutate business state.
     */
    public function allowsRead(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id): bool;

    /**
     * Returns a bounded trusted caller and visibility epoch binding for Common comparison continuity
     *
     * Bind the exact admitted reader and visibility history across processes. Rotate after any discontinuity,
     * including restored or revoked/regranted sharing. Without continuity use a fresh opaque reference so Common
     * restarts comparison safely. Do not derive an epoch from the handle, requested scope or Permission list alone.
     */
    public function comparisonScope(AuthenticatedAgentPrincipal $principal, string $scope, TaskId $id): string;
}
