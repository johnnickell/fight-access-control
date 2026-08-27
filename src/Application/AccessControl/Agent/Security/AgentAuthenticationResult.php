<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;

/**
 * Carries the current Agent credential authority confirmed by authentication.
 */
final readonly class AgentAuthenticationResult
{
    /**
     * Creates a secret-free authenticated Agent result.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision
    ) {
        if ($credentialRevision < 0) {
            throw new AgentAuthenticationRejectedException('Agent authentication rejected.');
        }
    }

    /**
     * Returns the authenticated Agent identifier.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the authenticated current credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the authenticated current credential revision.
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }
}
