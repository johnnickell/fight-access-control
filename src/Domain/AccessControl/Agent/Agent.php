<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;

/**
 * Represents one machine authority with its current HMAC credential.
 */
class Agent
{
    /**
     * Creates an Agent identity with its initial active credential.
     */
    protected function __construct(
        private readonly AgentId $id,
        private readonly AgentState $state,
        private readonly AgentCredentialId $credentialId,
        private readonly int $credentialRevision,
        private readonly string $encryptedHmacSharedSecretEnvelope,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $updatedAt
    ) {
    }

    /**
     * Provisions an active Agent with one initial encrypted HMAC credential.
     */
    public static function provision(
        AgentId $id,
        AgentCredentialId $credentialId,
        string $encryptedHmacSharedSecretEnvelope,
        DateTimeImmutable $provisionedAt
    ): self {
        return new self(
            $id,
            AgentState::ACTIVE,
            $credentialId,
            0,
            $encryptedHmacSharedSecretEnvelope,
            $provisionedAt,
            $provisionedAt
        );
    }

    /**
     * Returns the stable Agent identifier.
     */
    public function getId(): AgentId
    {
        return $this->id;
    }

    /**
     * Returns the Agent lifecycle state.
     */
    public function getState(): AgentState
    {
        return $this->state;
    }

    /**
     * Returns the public current credential identifier.
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the monotonic current credential revision.
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns the consumer-encrypted current HMAC shared-secret envelope.
     */
    public function getEncryptedHmacSharedSecretEnvelope(): string
    {
        return $this->encryptedHmacSharedSecretEnvelope;
    }

    /**
     * Returns the provisioning timestamp.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last-update timestamp.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
