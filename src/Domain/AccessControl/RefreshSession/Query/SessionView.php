<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Provides a safe immutable view of an active refresh session.
 */
final readonly class SessionView
{
    /**
     * Constructs the safe session view.
     */
    public function __construct(
        private RefreshSessionId $sessionId,
        private UserId $userId,
        private DateTimeImmutable $createdAt,
        private DateTimeImmutable $lastActivityAt,
        private DateTimeImmutable $idleExpiresAt,
        private DateTimeImmutable $absoluteExpiresAt,
        private bool $remembered,
        private bool $current
    ) {
    }

    /**
     * Creates a safe view without exposing authoritative or credential state.
     */
    public static function fromSession(RefreshSession $refreshSession, RefreshSessionId $currentSessionId): self
    {
        return new self(
            $refreshSession->getId(),
            $refreshSession->getUserId(),
            $refreshSession->getCreatedAt(),
            $refreshSession->getLastActivityAt(),
            $refreshSession->getIdleExpiresAt(),
            $refreshSession->getAbsoluteExpiresAt(),
            $refreshSession->isRemembered(),
            $refreshSession->getId()->equals($currentSessionId)
        );
    }

    /**
     * Returns the stable session identifier.
     */
    public function getSessionId(): RefreshSessionId
    {
        return $this->sessionId;
    }

    /**
     * Returns the identity that owns the session.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when the session was established.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns when the session last proved refresh activity.
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
     * Returns whether browser-restart persistence was requested.
     */
    public function isRemembered(): bool
    {
        return $this->remembered;
    }

    /**
     * Returns whether this session carried the request.
     */
    public function isCurrent(): bool
    {
        return $this->current;
    }
}
