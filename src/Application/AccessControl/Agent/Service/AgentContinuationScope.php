<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\AuthenticatedAgentPrincipal;
use SensitiveParameter;

/**
 * Interface AgentContinuationScope
 *
 * Owns trusted current relationship policy for one exact bounded action and its targets.
 */
interface AgentContinuationScope
{
    /**
     * Determines current consumer scope under the enclosing continuation fence
     *
     * The trusted composition binds all targets and the actual action, not untrusted selectors alone. Read current
     * authoritative relationships, fail on unverifiable state, and participate with every scope writer in the
     * composite fence. Do not release protection, mutate authority or infer relationships from broad Permissions.
     */
    public function isAllowed(#[SensitiveParameter] AuthenticatedAgentPrincipal $principal): bool;
}
