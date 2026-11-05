<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Query;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Class GetUserById
 *
 * Queries one user identity by its stable identifier.
 */
final readonly class GetUserById implements Query
{
    /**
     * Constructs GetUserById
     *
     * Constructs the user-identity query.
     */
    public function __construct(private UserId $userId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!isset($data['user_id']) || !is_string($data['user_id'])) {
            throw new DomainException('Missing or invalid User ID.');
        }

        return new static(UserId::fromString($data['user_id']));
    }

    /**
     * Returns the canonical User lookup payload
     *
     * @phpstan-return array{user_id: string}
     */
    public function toArray(): array
    {
        return ['user_id' => $this->userId->toString()];
    }

    /**
     * Returns the stable user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
