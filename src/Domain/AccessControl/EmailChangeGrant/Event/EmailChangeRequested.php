<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;
use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Class EmailChangeRequested
 *
 * Records that an email-change reservation and delivery became durable.
 */
final readonly class EmailChangeRequested implements Event
{
    /**
     * Constructs EmailChangeRequested
     *
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

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString($data['actor_id']),
            UserId::fromString($data['user_id']),
            EmailChangeDeliveryId::fromString($data['email_change_delivery_id']),
            EmailAddress::fromString($data['email']),
            CredentialDeliveryTimestamp::fromString($data['issued_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical request event payload
     *
     * @phpstan-return array{
     *     actor_id: string, user_id: string, email_change_delivery_id: string, email: string, issued_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'                 => $this->actorId->toString(),
            'user_id'                  => $this->userId->toString(),
            'email_change_delivery_id' => $this->emailChangeDeliveryId->toString(),
            'email'                    => $this->email->toString(),
            'issued_at'                => $this->issuedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the requesting actor identifier
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the owner identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the durable delivery identifier
     */
    public function getEmailChangeDeliveryId(): EmailChangeDeliveryId
    {
        return $this->emailChangeDeliveryId;
    }

    /**
     * Returns the reserved destination email
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Returns when authority was issued
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
