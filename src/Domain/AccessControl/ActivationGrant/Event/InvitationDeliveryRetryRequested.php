<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class InvitationDeliveryRetryRequested
 *
 * Requests an invocation-neutral retry of durable activation-delivery work.
 */
final readonly class InvitationDeliveryRetryRequested implements Event
{
    /**
     * Constructs InvitationDeliveryRetryRequested
     *
     * Constructs the activation-delivery retry-request event.
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private ActivationDeliveryId $activationDeliveryId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'activation_delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        return new static(
            $data['actor_id'],
            UserId::fromString($data['user_id']),
            ActivationDeliveryId::fromString($data['activation_delivery_id'])
        );
    }

    /**
     * Returns the canonical retry event payload
     *
     * @phpstan-return array{actor_id: string, user_id: string, activation_delivery_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'               => $this->actorId,
            'user_id'                => $this->userId->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString()
        ];
    }

    /**
     * Returns the actor who requested the retry
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the target user's identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the delivery generation selected for retry
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }
}
