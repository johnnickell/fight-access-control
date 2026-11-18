<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Detail;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Detail\Service\DetailAccess;
use Fight\Common\Domain\Mcp\Detail\DetailSelection;

/**
 * Interface AgentDetailScope
 */
interface AgentDetailScope
{
    /**
     * Returns current trusted structural, metadata and body scope for the authenticated Agent
     *
     * Selectors identify requested data, never grants. Consumers own relationships and sharing; a previous
     * reference, timeline grant or chunk supplies no authority. Faults must propagate, not become empty success.
     */
    public function currentAccess(AuthenticatedAgentPrincipal $principal, DetailSelection $selection): DetailAccess;
}
