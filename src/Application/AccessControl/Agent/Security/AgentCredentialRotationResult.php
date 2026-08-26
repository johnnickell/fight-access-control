<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use LogicException;
use SensitiveParameter;

/**
 * Carries one raw replacement Agent HMAC shared secret after committed rotation.
 */
final readonly class AgentCredentialRotationResult
{
    /**
     * Creates a non-serializable Agent credential rotation result.
     */
    public function __construct(
        private AgentId $agentId,
        private AgentCredentialId $credentialId,
        #[SensitiveParameter] private string $hmacSharedSecret
    ) {
    }

    /**
     * Returns the Agent whose credential was rotated.
     */
    public function getAgentId(): AgentId
    {
        return $this->agentId;
    }

    /**
     * Returns the successor credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the raw replacement HMAC shared secret exactly to the rotating caller.
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
        throw new LogicException('Agent credential rotation results cannot be serialized.');
    }
}
