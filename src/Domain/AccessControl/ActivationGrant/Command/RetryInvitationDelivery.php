<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Command;

use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class RetryInvitationDelivery
 *
 * Retries durable activation-delivery work for one pending user.
 */
final readonly class RetryInvitationDelivery implements Command
{
    /**
     * Constructs RetryInvitationDelivery
     *
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

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        return new static($data['actor_id'], UserId::fromString($data['user_id']));
    }

    /**
     * Returns the canonical retry command payload
     *
     * @phpstan-return array{actor_id: string, user_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId,
            'user_id'  => $this->userId->toString()
        ];
    }

    /**
     * Returns the actor retrying delivery
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the pending user's identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }
}
