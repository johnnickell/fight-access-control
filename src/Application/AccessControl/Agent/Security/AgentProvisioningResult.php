<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use LogicException;
use SensitiveParameter;

/**
 * Carries the first raw Agent HMAC shared secret after successful provisioning.
 */
final readonly class AgentProvisioningResult
{
    /**
     * Creates a non-serializable Agent provisioning result.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        #[SensitiveParameter] private string $hmacSharedSecret
    ) {
    }

    /**
     * Returns the provisioned Agent identifier.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the provisioned Agent credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the raw HMAC shared secret exactly to the provisioning caller.
     */
    public function getHmacSharedSecret(): string
    {
        return $this->hmacSharedSecret;
    }

    /**
     * Prevents the raw shared secret from being serialized into a message or durable store.
     */
    public function __serialize(): array
    {
        throw new LogicException('Agent provisioning results cannot be serialized.');
    }
}
