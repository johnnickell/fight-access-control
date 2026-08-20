<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

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
        private readonly DateTimeImmutable $activatedAt,
        private readonly int $authenticationVersion,
        private bool $revoked = false
    ) {
    }

    /**
     * Starts the first session at initial authentication version one.
     */
    public static function start(
        RefreshSessionId $id,
        UserId $userId,
        DateTimeImmutable $activatedAt
    ): self {
        return new self($id, $userId, $activatedAt, 1);
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
     * Returns when this first session was established.
     */
    public function getActivatedAt(): DateTimeImmutable
    {
        return $this->activatedAt;
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
}
