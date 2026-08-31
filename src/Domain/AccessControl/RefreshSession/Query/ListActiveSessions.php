<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Repository\Pagination;

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
        private RefreshSessionId $currentSessionId,
        private Pagination $pagination
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'current_session_id', 'page', 'per_page', 'orderings'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            RefreshSessionId::fromString((string) $data['current_session_id']),
            new Pagination((int) $data['page'], (int) $data['per_page'], $data['orderings'])
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
            'page' => $this->pagination->page(),
            'per_page' => $this->pagination->perPage(),
            'orderings' => $this->pagination->orderings(),
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

    /**
     * Returns the requested page configuration.
     */
    public function getPagination(): Pagination
    {
        return $this->pagination;
    }
}
