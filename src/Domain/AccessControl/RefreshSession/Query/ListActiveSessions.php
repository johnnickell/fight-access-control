<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Queries the active refresh sessions owned by a user.
 */
final readonly class ListActiveSessions implements Query
{
    /**
     * Constructs the active-session query.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private RefreshSessionId $currentSessionId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('actor_id', $data)) {
            throw new DomainException('Missing required key "actor_id" in data array');
        }

        if (!array_key_exists('user_id', $data)) {
            throw new DomainException('Missing required key "user_id" in data array');
        }

        if (!array_key_exists('current_session_id', $data)) {
            throw new DomainException('Missing required key "current_session_id" in data array');
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            RefreshSessionId::fromString((string) $data['current_session_id'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id' => $this->userId->toString(),
            'current_session_id' => $this->currentSessionId->toString(),
        ];
    }

    /**
     * Returns the user performing the request.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the user whose active sessions are requested.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the refresh session used for the request.
     */
    public function getCurrentSessionId(): RefreshSessionId
    {
        return $this->currentSessionId;
    }
}
