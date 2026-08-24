<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Type\Arrayable;

/**
 * Provides a safe immutable view of an active refresh session.
 */
final readonly class SessionView implements Arrayable
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

    /**
     * Returns the canonical safe array representation.
     *
     * @return array{
     *     session_id: string,
     *     user_id: string,
     *     created_at: string,
     *     last_activity_at: string,
     *     idle_expires_at: string,
     *     absolute_expires_at: string,
     *     remembered: bool,
     *     current: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId->toString(),
            'user_id' => $this->userId->toString(),
            'created_at' => $this->createdAt->format(DATE_ATOM),
            'last_activity_at' => $this->lastActivityAt->format(DATE_ATOM),
            'idle_expires_at' => $this->idleExpiresAt->format(DATE_ATOM),
            'absolute_expires_at' => $this->absoluteExpiresAt->format(DATE_ATOM),
            'remembered' => $this->remembered,
            'current' => $this->current,
        ];
    }
}
