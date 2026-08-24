<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Event;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a successfully confirmed user-invitation delivery.
 */
final readonly class UserInvitationDelivered implements Event
{
    /**
     * Creates the invitation-delivery success event.
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private ActivationDeliveryId $activationDeliveryId
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'activation_delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            (string) $data['actor_id'],
            UserId::fromString((string) $data['user_id']),
            ActivationDeliveryId::fromString((string) $data['activation_delivery_id'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id'               => $this->actorId,
            'user_id'                => $this->userId->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString(),
        ];
    }

    /**
     * Returns the actor that caused delivery invocation.
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the target user's identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the confirmed delivery generation.
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }
}
