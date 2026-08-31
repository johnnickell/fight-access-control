<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use DateInterval;
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
        /** @var list<string> */
        private readonly array $usedCredentialDigests,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $lastActivityAt,
        private readonly ?DateTimeImmutable $rotatedAt,
        private readonly DateTimeImmutable $idleExpiresAt,
        private readonly DateTimeImmutable $absoluteExpiresAt,
        private readonly int $authenticationVersion,
        private readonly bool $remembered,
        private readonly int $revision,
        private readonly bool $revoked
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
            [],
            $createdAt,
            $createdAt,
            null,
            $idleExpiresAt,
            $absoluteExpiresAt,
            $authenticationVersion,
            $remembered,
            0,
            false
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
     * Returns when this session last proved refresh activity.
     */
    public function getLastActivityAt(): DateTimeImmutable
    {
        return $this->lastActivityAt;
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
     * Returns whether the most recently used credential is inside the accepted conflict window.
     */
    public function matchesMostRecentlyUsedCredentialWithin(
        RefreshCredential $refreshCredential,
        DateTimeImmutable $presentedAt,
        DateInterval $conflictWindow
    ): bool {
        $mostRecentlyUsedCredentialDigest = $this->usedCredentialDigests[array_key_last(
            $this->usedCredentialDigests
        )] ?? null;

        return $mostRecentlyUsedCredentialDigest !== null
            && hash_equals($mostRecentlyUsedCredentialDigest, $refreshCredential->digest())
            && $this->rotatedAt instanceof DateTimeImmutable
            && $presentedAt >= $this->rotatedAt
            && $presentedAt < $this->rotatedAt->add($conflictWindow);
    }

    /**
     * Returns whether a credential digest was previously authoritative for this session.
     */
    public function matchesUsedCredential(RefreshCredential $refreshCredential): bool
    {
        $presentedDigest = $refreshCredential->digest();

        return array_any(
            $this->usedCredentialDigests,
            fn(string $usedCredentialDigest): bool => hash_equals($usedCredentialDigest, $presentedDigest)
        );
    }

    /**
     * Returns the authentication version captured by this session.
     */
    public function getAuthenticationVersion(): int
    {
        return $this->authenticationVersion;
    }

    /**
     * Returns the authoritative persistence revision.
     */
    public function getRevision(): int
    {
        return $this->revision;
    }

    /**
     * Rotates to a fresh credential while preserving the absolute session boundary.
     */
    public function rotate(
        RefreshCredential $refreshCredential,
        DateTimeImmutable $rotatedAt,
        DateTimeImmutable $idleExpiresAt
    ): self {
        if ($rotatedAt < $this->lastActivityAt) {
            throw new InvalidArgumentException('Refresh-session activity must advance monotonically.');
        }

        if (!$this->isUsableAt($rotatedAt)) {
            throw new InvalidArgumentException('Refresh session must be usable when rotated.');
        }

        if ($idleExpiresAt <= $rotatedAt || $idleExpiresAt > $this->absoluteExpiresAt) {
            throw new InvalidArgumentException('Refresh-session rotation must remain within its absolute lifetime.');
        }

        return new self(
            $this->id,
            $this->userId,
            $refreshCredential->digest(),
            [...$this->usedCredentialDigests, $this->credentialDigest],
            $this->createdAt,
            $rotatedAt,
            $rotatedAt,
            $idleExpiresAt,
            $this->absoluteExpiresAt,
            $this->authenticationVersion,
            $this->remembered,
            $this->revision + 1,
            $this->revoked
        );
    }

    /**
     * Returns an immutable replacement that revokes this authoritative session.
     */
    public function revoke(): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->credentialDigest,
            $this->usedCredentialDigests,
            $this->createdAt,
            $this->lastActivityAt,
            $this->rotatedAt,
            $this->idleExpiresAt,
            $this->absoluteExpiresAt,
            $this->authenticationVersion,
            $this->remembered,
            $this->revision + 1,
            true
        );
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
