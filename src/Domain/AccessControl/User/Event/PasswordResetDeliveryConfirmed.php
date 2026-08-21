<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records that password-reset delivery was confirmed and its ciphertext destroyed.
 */
final readonly class PasswordResetDeliveryConfirmed implements Event
{
    /**
     * Creates the secret-free confirmation event.
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private PasswordResetDeliveryId $passwordResetDeliveryId,
        private DateTimeImmutable $occurredAt
    ) {
    }

    /** @inheritDoc */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'password_reset_delivery_id', 'occurred_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            (string) $data['actor_id'],
            UserId::fromString((string) $data['user_id']),
            PasswordResetDeliveryId::fromString((string) $data['password_reset_delivery_id']),
            new DateTimeImmutable((string) $data['occurred_at'])
        );
    }

    /** @inheritDoc */
    public function toArray(): array
    {
        return [
            'actor_id'    => $this->actorId,
            'user_id'     => $this->userId->toString(),
            'password_reset_delivery_id' => $this->passwordResetDeliveryId->toString(),
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the consumer actor that confirmed delivery.
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the target user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the confirmed delivery-generation identifier.
     */
    public function getPasswordResetDeliveryId(): PasswordResetDeliveryId
    {
        return $this->passwordResetDeliveryId;
    }

    /**
     * Returns when delivery was confirmed.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
