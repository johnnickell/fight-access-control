<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use InvalidArgumentException;

/**
 * Represents authoritative server-side state for an authenticated refresh session.
 */
class RefreshSession
{
    /**
     * Creates a first refresh session for an activated identity.
     */
    protected function __construct(
        private readonly RefreshSessionId $id,
        private readonly UserId $userId,
        private readonly string $credentialDigest,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $idleExpiresAt,
        private readonly DateTimeImmutable $absoluteExpiresAt,
        private readonly int $authenticationVersion,
        private readonly bool $remembered,
        private bool $revoked = false
    ) {
    }

    /**
     * Starts the first session at initial authentication version one.
     */
    public static function start(
        RefreshSessionId $id,
        UserId $userId,
        RefreshCredential $refreshCredential,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $idleExpiresAt,
        DateTimeImmutable $absoluteExpiresAt,
        int $authenticationVersion,
        bool $remembered
    ): self {
        if (
            $idleExpiresAt <= $createdAt
            || $absoluteExpiresAt <= $createdAt
            || $idleExpiresAt > $absoluteExpiresAt
        ) {
            throw new InvalidArgumentException('Refresh-session expiry must form a valid bounded lifetime.');
        }

        return new self(
            $id,
            $userId,
            $refreshCredential->digest(),
            $createdAt,
            $idleExpiresAt,
            $absoluteExpiresAt,
            $authenticationVersion,
            $remembered
        );
    }

    /**
     * Returns the stable session identifier.
     */
    public function getId(): RefreshSessionId
    {
        return $this->id;
    }

    /**
     * Returns the identity that owns this session.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the one-way credential digest for persistence.
     */
    public function getCredentialDigest(): string
    {
        return $this->credentialDigest;
    }

    /**
     * Returns when this first session was established.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the current idle deadline.
     */
    public function getIdleExpiresAt(): DateTimeImmutable
    {
        return $this->idleExpiresAt;
    }

    /**
     * Returns the immutable absolute deadline.
     */
    public function getAbsoluteExpiresAt(): DateTimeImmutable
    {
        return $this->absoluteExpiresAt;
    }

    /**
     * Returns whether browser-restart persistence was requested for this session.
     */
    public function isRemembered(): bool
    {
        return $this->remembered;
    }

    /**
     * Returns whether a presented refresh credential matches this session.
     */
    public function matchesCredential(RefreshCredential $refreshCredential): bool
    {
        return hash_equals($this->credentialDigest, $refreshCredential->digest());
    }

    /**
     * Returns the authentication version captured by this session.
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Revokes this specific authoritative session.
     */
    public function revoke(): void
    {
        $this->revoked = true;
    }

    /**
     * Returns whether this authoritative session is no longer usable.
     */
    public function isRevoked(): bool
    {
        return $this->revoked;
    }

    /**
     * Returns whether the session remains authoritative at the supplied time.
     */
    public function isUsableAt(DateTimeImmutable $at): bool
    {
        return !$this->revoked && $at < $this->idleExpiresAt && $at < $this->absoluteExpiresAt;
    }
}
