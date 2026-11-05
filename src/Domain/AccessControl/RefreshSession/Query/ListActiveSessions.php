<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;
use Fight\Common\Domain\Repository\Pagination;

/**
 * Class ListActiveSessions
 *
 * Queries the active refresh sessions owned by a user.
 */
final readonly class ListActiveSessions implements Query
{
    /**
     * Constructs ListActiveSessions
     *
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

        foreach (['actor_id', 'user_id', 'current_session_id'] as $key) {
            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid string "%s" in session query data', $key));
            }
        }

        if (!is_int($data['page']) || !is_int($data['per_page']) || !is_array($data['orderings'])) {
            throw new DomainException('Pagination requires integer bounds and an ordering map.');
        }

        foreach ($data['orderings'] as $field => $direction) {
            if (!is_string($field) || !is_string($direction)) {
                throw new DomainException('Pagination orderings require named string directions.');
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            RefreshSessionId::fromString($data['current_session_id']),
            new Pagination($data['page'], $data['per_page'], $data['orderings'])
        );
    }

    /**
     * Returns the canonical active-session query payload
     *
     * @phpstan-return array{
     *     actor_id: string, user_id: string, current_session_id: string,
     *     page: int, per_page: int, orderings: array<string, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'           => $this->actorId->toString(),
            'user_id'            => $this->userId->toString(),
            'current_session_id' => $this->currentSessionId->toString(),
            'page'               => $this->pagination->page(),
            'per_page'           => $this->pagination->perPage(),
            'orderings'          => $this->pagination->orderings()
        ];
    }

    /**
     * Returns the user performing the request
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the user whose active sessions are requested
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the refresh session used for the request
     */
    public function getCurrentSessionId(): RefreshSessionId
    {
        return $this->currentSessionId;
    }

    /**
     * Returns the requested page configuration
     */
    public function getPagination(): Pagination
    {
        return $this->pagination;
    }
}
