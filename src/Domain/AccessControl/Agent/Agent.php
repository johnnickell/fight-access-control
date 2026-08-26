<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

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
        /** @var list<PermissionId> */
        private readonly array $permissionIds,
        private readonly int $permissionAssignmentRevision,
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
            [],
            1,
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
     * Returns the directly assigned Permission identities.
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the monotonic Permission-assignment revision.
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }

    /**
     * Returns whether the Permission is directly assigned.
     */
    public function hasPermission(PermissionId $permissionId): bool
    {
        return array_any(
            $this->permissionIds,
            static fn(PermissionId $assigned): bool => $assigned->equals($permissionId)
        );
    }

    /**
     * Returns the immutable successor with one newly assigned Permission.
     */
    public function grantPermission(PermissionId $permissionId, DateTimeImmutable $grantedAt): self
    {
        if ($this->hasPermission($permissionId)) {
            throw new AgentPermissionAssignmentException('The Permission is already assigned to the Agent.');
        }

        return new self(
            $this->id,
            $this->name,
            $this->state,
            $this->credentialId,
            $this->credentialRevision,
            $this->encryptedHmacSharedSecretEnvelope,
            [...$this->permissionIds, $permissionId],
            $this->permissionAssignmentRevision + 1,
            $this->createdAt,
            $grantedAt
        );
    }

    /**
     * Returns the immutable successor without one directly assigned Permission.
     */
    public function revokePermission(PermissionId $permissionId, DateTimeImmutable $revokedAt): self
    {
        if (!$this->hasPermission($permissionId)) {
            throw new AgentPermissionAssignmentException('The Permission is not assigned to the Agent.');
        }

        return new self(
            $this->id,
            $this->name,
            $this->state,
            $this->credentialId,
            $this->credentialRevision,
            $this->encryptedHmacSharedSecretEnvelope,
            array_values(array_filter(
                $this->permissionIds,
                static fn(PermissionId $assigned): bool => !$assigned->equals($permissionId)
            )),
            $this->permissionAssignmentRevision + 1,
            $this->createdAt,
            $revokedAt
        );
    }

    /**
     * Returns the immutable successor with the complete direct-Permission assignment set.
     *
     * @phpstan-param iterable<PermissionId> $permissionIds
     */
    public function replacePermissions(
        iterable $permissionIds,
        int $expectedPermissionAssignmentRevision,
        DateTimeImmutable $replacedAt
    ): self {
        $replacementIds = [];
        $seen = [];
        foreach ($permissionIds as $permissionId) {
            $key = $permissionId->toString();
            if (isset($seen[$key])) {
                throw new AgentPermissionAssignmentException(
                    'The complete Agent Permission assignment set contains a duplicate.'
                );
            }

            $seen[$key] = true;
            $replacementIds[] = $permissionId;
        }

        if ($expectedPermissionAssignmentRevision !== $this->permissionAssignmentRevision) {
            throw new AgentPermissionAssignmentException('The Agent Permission assignment revision is stale.');
        }

        if (
            count($replacementIds) === count($this->permissionIds)
            && array_all($replacementIds, fn(PermissionId $id): bool => $this->hasPermission($id))
        ) {
            return $this;
        }

        return new self(
            $this->id,
            $this->name,
            $this->state,
            $this->credentialId,
            $this->credentialRevision,
            $this->encryptedHmacSharedSecretEnvelope,
            $replacementIds,
            $this->permissionAssignmentRevision + 1,
            $this->createdAt,
            $replacedAt
        );
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
            $this->permissionIds,
            $this->permissionAssignmentRevision,
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
            $this->permissionIds,
            $this->permissionAssignmentRevision,
            $this->createdAt,
            $revokedAt
        );
    }
}
