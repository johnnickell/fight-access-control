<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\AccessControl\Domain\AccessControl\User\UserState;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class UserRestored
 *
 * Records an administrative identity restoration after durable commit.
 */
final readonly class UserRestored implements Event
{
    /**
     * Constructs UserRestored
     *
     * Creates a user-restored event.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private UserState $restorationState,
        private ?ActivationDeliveryId $activationDeliveryId = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'restoration_state', 'activation_delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        foreach (['actor_id', 'user_id', 'restoration_state'] as $key) {
            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid string "%s" in restoration data', $key));
            }
        }

        $activationDeliveryId = $data['activation_delivery_id'];
        if ($activationDeliveryId !== null && !is_string($activationDeliveryId)) {
            throw new DomainException('The activation delivery ID must be a string or null.');
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            UserState::from($data['restoration_state']),
            $activationDeliveryId === null ? null : ActivationDeliveryId::fromString($activationDeliveryId)
        );
    }

    /**
     * Returns the canonical restoration fact
     *
     * @phpstan-return array{
     *     actor_id: string, user_id: string, restoration_state: value-of<UserState>,
     *     activation_delivery_id: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'               => $this->actorId->toString(),
            'user_id'                => $this->userId->toString(),
            'restoration_state'      => $this->restorationState->value,
            'activation_delivery_id' => $this->activationDeliveryId?->toString()
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
     * Returns the restored user
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the chosen restoration target state
     */
    public function getRestorationState(): UserState
    {
        return $this->restorationState;
    }

    /**
     * Returns the replacement activation delivery, if one was issued
     */
    public function getActivationDeliveryId(): ?ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }
}
