<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;

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
        private readonly AgentName $name,
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
        AgentName $name,
        AgentCredentialId $credentialId,
        string $encryptedHmacSharedSecretEnvelope,
        DateTimeImmutable $provisionedAt
    ): self {
        return new self(
            $id,
            $name,
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
     * Returns the required operator-facing name.
     */
    public function getName(): AgentName
    {
        return $this->name;
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

    /**
     * Returns the immutable successor with one immediately active credential.
     */
    public function rotateCredential(
        AgentCredentialId $expectedCredentialId,
        AgentCredentialId $successorCredentialId,
        string $encryptedHmacSharedSecretEnvelope,
        DateTimeImmutable $rotatedAt
    ): self {
        if ($this->state !== AgentState::ACTIVE || !$this->credentialId->equals($expectedCredentialId)) {
            throw new AgentCredentialException('The expected Agent credential is no longer active.');
        }

        return new self(
            $this->id,
            $this->name,
            $this->state,
            $successorCredentialId,
            $this->credentialRevision + 1,
            $encryptedHmacSharedSecretEnvelope,
            $this->createdAt,
            $rotatedAt
        );
    }

    /**
     * Returns the terminally revoked Agent authority.
     */
    public function revoke(DateTimeImmutable $revokedAt): self
    {
        if ($this->state !== AgentState::ACTIVE) {
            throw new AgentCredentialException('The Agent credential is no longer active.');
        }

        return new self(
            $this->id,
            $this->name,
            AgentState::REVOKED,
            $this->credentialId,
            $this->credentialRevision,
            $this->encryptedHmacSharedSecretEnvelope,
            $this->createdAt,
            $revokedAt
        );
    }
}
