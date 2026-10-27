<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentContinuationContext;

/**
 * Interface AgentContinuationHandoff
 *
 * Verifies consumer-owned worker provenance, never caller-supplied claims alone.
 */
interface AgentContinuationHandoff
{
    /**
     * Returns original context only after verifying the trusted producer and intended worker handoff
     *
     * Verify an integrity-protected, access-controlled record rooted in successful request admission, its exact
     * original Agent/credential/revision/authentication time and intended work/recipient. Enforce current handoff
     * validity and revocation policy. Reject missing, modified, foreign or unverifiable evidence by throwing.
     * Do not refresh the authentication time, adopt new credentials or treat a protocol handle as provenance.
     * This injected capability is trusted infrastructure, not a verifier selected or implemented by the caller.
     */
    public function verify(): AgentContinuationContext;
}
