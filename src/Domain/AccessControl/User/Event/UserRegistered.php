<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationDeliveryId;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class UserRegistered
 *
 * Records committed pending registration, not delivery or activation.
 */
final readonly class UserRegistered implements Event
{
    /**
     * Constructs UserRegistered
     */
    public function __construct(
        private UserId $userId,
        private ActivationDeliveryId $activationDeliveryId,
        private EmailAddress $email,
        private DateTimeImmutable $issuedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'activation_delivery_id', 'email', 'issued_at'] as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                throw new DomainException('Missing or invalid registration fact field.');
            }
        }

        return new static(
            UserId::fromString($data['user_id']),
            ActivationDeliveryId::fromString($data['activation_delivery_id']),
            EmailAddress::fromString($data['email']),
            CredentialDeliveryTimestamp::fromString($data['issued_at'])->toDateTimeImmutable()
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'                => $this->userId->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString(),
            'email'                  => $this->email->toString(),
            'issued_at'              => $this->issuedAt->format('Y-m-d\TH:i:s.uP')
        ];
    }

    /**
     * Returns the registered pending User identity
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the committed activation delivery generation
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }

    /**
     * Returns the registered email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns the registration issuance time
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
