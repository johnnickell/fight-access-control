<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class PendingRegistrationRecovered
 *
 * Records confirmed replacement, not provider acceptance or activation.
 */
final readonly class PendingRegistrationRecovered implements Event
{
    /**
     * Constructs PendingRegistrationRecovered
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private ActivationDeliveryId $previousActivationDeliveryId,
        private ActivationDeliveryId $activationDeliveryId,
        private EmailAddress $email
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        $keys = ['actor_id', 'user_id', 'previous_activation_delivery_id', 'activation_delivery_id', 'email'];
        if (array_diff(array_keys($data), $keys) !== []) {
            throw new DomainException('Unexpected registration recovery event data.');
        }

        foreach ($keys as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException('Missing or invalid registration recovery event data.');
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            ActivationDeliveryId::fromString($data['previous_activation_delivery_id']),
            ActivationDeliveryId::fromString($data['activation_delivery_id']),
            EmailAddress::fromString($data['email'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id'                        => $this->actorId->toString(),
            'user_id'                         => $this->userId->toString(),
            'previous_activation_delivery_id' => $this->previousActivationDeliveryId->toString(),
            'activation_delivery_id'          => $this->activationDeliveryId->toString(),
            'email'                           => $this->email->toString()
        ];
    }

    /**
     * Returns the admitted administrator
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the retained User
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the replaced generation identity
     */
    public function getPreviousActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->previousActivationDeliveryId;
    }

    /**
     * Returns the successor delivery identity
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }

    /**
     * Returns the committed canonical destination
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
