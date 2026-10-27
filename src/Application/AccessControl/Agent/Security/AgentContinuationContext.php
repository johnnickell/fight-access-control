<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use DateInterval;
use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;

/**
 * Class AgentContinuationContext
 *
 * Carries original authentication claims, not proof of admission or a Permission grant.
 */
final readonly class AgentContinuationContext
{
    /**
     * Constructs AgentContinuationContext
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        private int $credentialRevision,
        private DateTimeImmutable $authenticatedAt
    ) {
    }

    /**
     * Returns the originally authenticated Agent identity
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the originally authenticated credential identity
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the originally authenticated credential revision
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns the original authentication admission time
     */
    public function getAuthenticatedAt(): DateTimeImmutable
    {
        return $this->authenticatedAt;
    }

    /**
     * Determines whether the original five-minute provenance window remains valid
     */
    public function isValidAt(DateTimeImmutable $now): bool
    {
        return $this->credentialRevision >= 0
            && $now >= $this->authenticatedAt
            && $now < $this->authenticatedAt->add(new DateInterval('PT5M'));
    }

    /**
     * Returns an empty diagnostic projection
     *
     * @return array<never, never>
     */
    public function __debugInfo(): array
    {
        return [];
    }
}
