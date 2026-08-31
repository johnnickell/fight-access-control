<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Records that an email-change reservation and delivery became durable.
 */
final readonly class EmailChangeRequested implements Event
{
    /**
     * Creates a secret-free email-change request event.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private EmailChangeDeliveryId $emailChangeDeliveryId,
        private EmailAddress $email,
        private DateTimeImmutable $issuedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'email_change_delivery_id', 'email', 'issued_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            EmailChangeDeliveryId::fromString((string) $data['email_change_delivery_id']),
            EmailAddress::fromString((string) $data['email']),
            new DateTimeImmutable((string) $data['issued_at'])
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
            'email_change_delivery_id' => $this->emailChangeDeliveryId->toString(),
            'email' => $this->email->toString(),
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the requesting actor identifier.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the owner identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the durable delivery identifier.
     */
    public function getEmailChangeDeliveryId(): EmailChangeDeliveryId
    {
        return $this->emailChangeDeliveryId;
    }

    /**
     * Returns the reserved destination email.
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns when authority was issued.
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
