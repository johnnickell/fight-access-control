<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Query;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Queries the safe operational status for a user's activation delivery work.
 */
final readonly class GetActivationDeliveryStatus implements Query
{
    /**
     * Constructs the delivery-status query.
     */
    public function __construct(private UserId $userId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('user_id', $data)) {
            throw new DomainException('Missing required key "user_id" in data array');
        }

        return new static(UserId::fromString((string) $data['user_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['user_id' => $this->userId->toString()];
    }

    /**
     * Returns the user whose activation delivery status is requested.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
