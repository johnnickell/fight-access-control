<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Domain\Mcp\Notice\Notice;

/**
 * Interface AgentSubscriptionScope
 *
 * Supplies independent trusted whole-signal scope, never inferred from a visible item or source Query grant.
 * Null notice admits opening only. Catalog activity, selected exact URIs, complete Skill/member boundaries and
 * every source metadata field require current consumer policy. Faults must throw, not become healthy empty output.
 */
interface AgentSubscriptionScope
{
    /**
     * Returns current permission for the entire attempted signal independently of family availability
     */
    public function allows(
        AuthenticatedAgentPrincipal $principal,
        string $filter,
        ?string $uri,
        ?Notice $notice
    ): bool;
}
