<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class PendingInvitationCorrected
 *
 * Records a corrected pending invitation after durable commit.
 */
final readonly class PendingInvitationCorrected implements Event
{
    /**
     * Constructs PendingInvitationCorrected
     *
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
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            EmailAddress::fromString($data['email']),
            ActivationDeliveryId::fromString($data['activation_delivery_id'])
        );
    }

    /**
     * Returns the canonical invitation-correction fact
     *
     * @phpstan-return array{actor_id: string, user_id: string, email: string, activation_delivery_id: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'               => $this->actorId->toString(),
            'user_id'                => $this->userId->toString(),
            'email'                  => $this->email->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString()
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
     * Returns the corrected pending user
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the corrected email
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the replacement activation delivery
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }
}
