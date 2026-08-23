<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Records a corrected pending invitation after durable commit.
 */
final readonly class PendingInvitationCorrected implements Event
{
    /**
     * Creates a pending-invitation correction event.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private EmailAddress $email,
        private ActivationDeliveryId $activationDeliveryId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email', 'activation_delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            EmailAddress::fromString((string) $data['email']),
            ActivationDeliveryId::fromString((string) $data['activation_delivery_id'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id' => $this->userId->toString(),
            'email' => $this->email->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString(),
        ];
    }

    /**
     * Returns the administrative actor.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the corrected pending user.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the corrected email.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the replacement activation delivery.
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }
}
