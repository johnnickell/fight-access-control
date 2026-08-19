<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Retries durable activation-delivery work for one pending user.
 */
final readonly class RetryActivationDelivery implements Command
{
    /**
     * Constructs the activation-delivery retry command.
     */
    public function __construct(
        private string $actorId,
        private UserId $userId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static((string) $data['actor_id'], UserId::fromString((string) $data['user_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId,
            'user_id'  => $this->userId->toString(),
        ];
    }

    /**
     * Returns the actor retrying delivery.
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the pending user's identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
