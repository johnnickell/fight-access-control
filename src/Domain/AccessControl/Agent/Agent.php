<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentCredentialException;
use Fight\AccessControl\Domain\AccessControl\Agent\Exception\AgentPermissionAssignmentException;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use SensitiveParameter;

/**
 * Class Agent
 *
 * Represents one machine authority with its current HMAC credential.
 */
class Agent
{
    /**
     * Constructs Agent
     *
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
     * Provisions an active Agent with one initial encrypted HMAC credential
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
     * Reconstitutes persisted authority without issuing credentials
     *
     * Repositories validate current operation correlation under the shared credential fence.
     *
     * @phpstan-param array<array-key, PermissionId> $permissionIds
     */
    public static function reconstitute(
        AgentId $id,
        AgentName $name,
        AgentState $state,
        AgentCredentialId $credentialId,
        int $credentialRevision,
        #[SensitiveParameter] string $encryptedHmacSharedSecretEnvelope,
        array $permissionIds,
        int $permissionAssignmentRevision,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt
    ): self {
        $permissionKeys = array_map(static fn(PermissionId $id): string => $id->toString(), $permissionIds);
        if (
            $credentialRevision < 0
            || $permissionAssignmentRevision < 1
            || $encryptedHmacSharedSecretEnvelope === ''
            || $updatedAt < $createdAt
            || !array_is_list($permissionIds)
            || count($permissionKeys) !== count(array_unique($permissionKeys))
        ) {
            throw new AgentCredentialException('The persisted Agent authority is invalid.');
        }

        return new self(
            $id,
            $name,
            $state,
            $credentialId,
            $credentialRevision,
            $encryptedHmacSharedSecretEnvelope,
            $permissionIds,
            $permissionAssignmentRevision,
            $createdAt,
            $updatedAt
        );
    }

    /**
     * Returns the stable Agent identifier
     */
    public function getId(): AgentId
    {
        return $this->id;
    }

    /**
     * Returns the required operator-facing name
     */
    public function getName(): AgentName
    {
        return $this->name;
    }

    /**
     * Returns the Agent lifecycle state
     */
    public function getState(): AgentState
    {
        return $this->state;
    }

    /**
     * Returns the public current credential identifier
     */
    public function getCredentialId(): AgentCredentialId
    {
        return $this->credentialId;
    }

    /**
     * Returns the monotonic current credential revision
     */
    public function getCredentialRevision(): int
    {
        return $this->credentialRevision;
    }

    /**
     * Returns the consumer-encrypted current HMAC shared-secret envelope
     */
    public function getEncryptedHmacSharedSecretEnvelope(): string
    {
        return $this->encryptedHmacSharedSecretEnvelope;
    }

    /**
     * Returns the directly assigned Permission identities
     *
     * @return list<PermissionId>
     */
    public function getPermissionIds(): array
    {
        return $this->permissionIds;
    }

    /**
     * Returns the monotonic Permission-assignment revision
     */
    public function getPermissionAssignmentRevision(): int
    {
        return $this->permissionAssignmentRevision;
    }

    /**
     * Returns whether the Permission is directly assigned
     */
    public function hasPermission(PermissionId $permissionId): bool
    {
        return array_any(
            $this->permissionIds,
            static fn(PermissionId $assigned): bool => $assigned->equals($permissionId)
        );
    }

    /**
     * Returns the immutable successor with one newly assigned Permission
     */
    public function grantPermission(PermissionId $permissionId, DateTimeImmutable $grantedAt): self
    {
        if ($this->hasPermission($permissionId)) {
            return $this;
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
     * Returns the immutable successor without one directly assigned Permission
     */
    public function revokePermission(PermissionId $permissionId, DateTimeImmutable $revokedAt): self
    {
        if (!$this->hasPermission($permissionId)) {
            return $this;
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
     * Returns the immutable successor with the complete direct-Permission assignment set
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
                continue;
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
     * Returns the provisioning timestamp
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the last-update timestamp
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Validates the predecessor for a new recoverable rotation before generating any material
     */
    public function assertRecoverableRotation(AgentCredentialId $expectedCredentialId, int $expectedRevision): void
    {
        if (
            $this->state !== AgentState::ACTIVE
            || !$this->credentialId->equals($expectedCredentialId)
            || $this->credentialRevision !== $expectedRevision
            || $expectedRevision === PHP_INT_MAX
        ) {
            throw new AgentCredentialException('The expected Agent credential is no longer active.');
        }
    }

    /**
     * Creates one recoverable successor for atomic persistence with its operation and predecessor cancellation
     */
    public function rotateRecoverableCredential(
        AgentCredentialId $expectedCredentialId,
        int $expectedRevision,
        AgentCredentialId $successorCredentialId,
        #[SensitiveParameter] string $encryptedHmacSharedSecretEnvelope,
        DateTimeImmutable $rotatedAt
    ): self {
        $this->assertRecoverableRotation($expectedCredentialId, $expectedRevision);
        if ($successorCredentialId->equals($this->credentialId) || $rotatedAt < $this->updatedAt) {
            throw new AgentCredentialException('The Agent credential successor is invalid.');
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
     * Returns the terminally revoked Agent authority
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

    /**
     * Returns whether a proposed persisted successor retires exactly this credential authority
     *
     * Repositories apply this invariant under their authoritative expected-state fence, including direct writes.
     * Every rotation and revocation requires atomic operation correlation and predecessor cancellation.
     */
    public function canReplaceCredentialWith(#[SensitiveParameter] self $replacement): bool
    {
        if (
            $this->state !== AgentState::ACTIVE
            || !$replacement->id->equals($this->id)
            || !$replacement->name->equals($this->name)
            || $replacement->createdAt != $this->createdAt
            || $replacement->updatedAt < $this->updatedAt
            || $replacement->permissionAssignmentRevision !== $this->permissionAssignmentRevision
            || count($replacement->permissionIds) !== count($this->permissionIds)
            || !array_all($replacement->permissionIds, fn(PermissionId $id): bool => $this->hasPermission($id))
            || !array_all($this->permissionIds, fn(PermissionId $id): bool => $replacement->hasPermission($id))
        ) {
            return false;
        }

        if ($replacement->state === AgentState::REVOKED) {
            return $replacement->credentialId->equals($this->credentialId)
                && $replacement->credentialRevision === $this->credentialRevision
                && $replacement->encryptedHmacSharedSecretEnvelope === $this->encryptedHmacSharedSecretEnvelope;
        }

        return $replacement->state === AgentState::ACTIVE
            && !$replacement->credentialId->equals($this->credentialId)
            && $replacement->credentialRevision === $this->credentialRevision + 1;
    }
}
