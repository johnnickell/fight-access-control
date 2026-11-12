<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Notice;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use Fight\Common\Application\Mcp\Subscription\Service\SubscriberAccess;

/**
 * Interface AgentSubscriberReadPolicy
 */
interface AgentSubscriberReadPolicy
{
    /**
     * Returns independently trusted whole-registration metadata scope for this caller
     *
     * Excludes rows unless all identities, requested/accepted filters and references may be disclosed.
     * Caller selectors, role labels, listening and heartbeats are not inspection grants.
     * Operational failures propagate to Common's single safe reporting boundary, never empty success.
     */
    public function accessFor(AuthenticatedAgentPrincipal $principal): SubscriberAccess;
}
