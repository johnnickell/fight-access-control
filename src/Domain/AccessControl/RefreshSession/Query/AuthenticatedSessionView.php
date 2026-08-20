<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Provides the safe framework-neutral view of a revalidated authenticated session.
 */
final readonly class AuthenticatedSessionView
{
    /**
     * Constructs the safe authenticated-session view.
     */
    public function __construct(private RefreshSessionId $refreshSessionId, private UserId $userId)
    {
    }

    /**
     * Creates the safe view from an authoritative refresh session.
     */
    public static function fromRefreshSession(RefreshSession $refreshSession): self
    {
        return new self($refreshSession->getId(), $refreshSession->getUserId());
    }

    /**
     * Returns the authoritative refresh-session identifier.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns the stable identity that owns the revalidated session.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
