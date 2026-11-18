<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Execution;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Session\Service\ExecutionAccess;
use Fight\Common\Domain\Mcp\Session\ExecutionSelection;

/**
 * Interface AgentExecutionScope
 */
interface AgentExecutionScope
{
    /**
     * Returns trusted invocation-local relationship and independent reference-disclosure grants
     *
     * Consumers own authoritative project/session/phase/activity relationships, sharing and invocation stability.
     * Selectors, labels, prior pages and references confer no authority. Faults propagate rather than deny silently.
     */
    public function currentAccess(
        AuthenticatedAgentPrincipal $principal,
        ExecutionSelection $selection
    ): ExecutionAccess;
}
