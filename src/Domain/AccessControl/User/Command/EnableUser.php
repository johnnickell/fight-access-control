<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class EnableUser
 *
 * Requests an administrative reactivation of a disabled identity.
 */
final readonly class EnableUser implements Command
{
    /**
     * Constructs EnableUser
     *
     * Creates a user-enable command.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                throw new DomainException(sprintf('Missing or invalid string "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id'])
        );
    }

    /**
     * Returns the canonical enablement payload
     *
     * @phpstan-return array{actor_id: string, user_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id'  => $this->userId->toString()
        ];
    }

    /**
     * Returns the administrative actor
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the target user
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
