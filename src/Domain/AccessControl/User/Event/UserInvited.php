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
 * Class UserInvited
 */
final readonly class UserInvited implements Event
{
    /**
     * Constructs UserInvited
     */
    public function __construct(
        private string $actorId,
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
        foreach (['actor_id', 'user_id', 'activation_delivery_id', 'email', 'issued_at'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            $data['actor_id'],
            UserId::fromString($data['user_id']),
            ActivationDeliveryId::fromString($data['activation_delivery_id']),
            EmailAddress::fromString($data['email']),
            CredentialDeliveryTimestamp::fromString($data['issued_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical invitation fact
     *
     * @phpstan-return array{
     *     actor_id: string, user_id: string, activation_delivery_id: string, email: string, issued_at: string
     * }
     */
    public function toArray(): array
    {
        return [
            'actor_id'               => $this->actorId,
            'user_id'                => $this->userId->toString(),
            'activation_delivery_id' => $this->activationDeliveryId->toString(),
            'email'                  => $this->email->toString(),
            'issued_at'              => $this->issuedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Retrieves the inviting actor ID
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Retrieves the invited user ID
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the initial activation-delivery generation
     */
    public function getActivationDeliveryId(): ActivationDeliveryId
    {
        return $this->activationDeliveryId;
    }

    /**
     * Retrieves the invited email address
     */
    public function getEmail(): EmailAddress
    {
        return $this->email;
    }

    /**
     * Retrieves the invitation issuance time
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
