<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentAuthenticationRejectedException;

/**
 * Carries the current Agent authority confirmed by authentication.
 */
final readonly class AgentAuthenticationResult
{
    /**
     * Creates a secret-free authenticated Agent result.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private int $permissionAssignmentRevision
    ) {
        if ($credentialRevision < 0 || $permissionAssignmentRevision < 1) {
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

    /**
     * Returns the authenticated current Permission-assignment revision.
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }
}
