<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Command;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class RecoverPendingRegistration
 */
final readonly class RecoverPendingRegistration implements Command
{
    /**
     * Constructs RecoverPendingRegistration
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private ActivationDeliveryId $expectedActivationDeliveryId,
        private EmailAddress $email
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        $keys = ['actor_id', 'user_id', 'expected_activation_delivery_id', 'email'];
        if (array_diff(array_keys($data), $keys) !== []) {
            throw new DomainException('Unexpected registration recovery data.');
        }

        foreach ($keys as $key) {
            if (!isset($data[$key]) || !is_string($data[$key])) {
                throw new DomainException('Missing or invalid registration recovery data.');
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            ActivationDeliveryId::fromString($data['expected_activation_delivery_id']),
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
            'expected_activation_delivery_id' => $this->expectedActivationDeliveryId->toString(),
            'email'                           => $this->email->toString()
        ];
    }

    /**
     * Returns the claimed administrator identity, not authorization
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the exact target identity
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the expected activation generation
     */
    public function getExpectedActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->expectedActivationDeliveryId;
    }

    /**
     * Returns the requested canonical destination
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }
}
