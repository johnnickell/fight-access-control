<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records that password-reset delivery work became durable.
 */
final readonly class PasswordResetRequested implements Event
{
    /**
     * Constructs the secret-free password-reset request event.
     */
    public function __construct(
        private UserId $userId,
        private PasswordResetDeliveryId $passwordResetDeliveryId,
        private DateTimeImmutable $issuedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'password_reset_delivery_id', 'issued_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            PasswordResetDeliveryId::fromString((string) $data['password_reset_delivery_id']),
            new DateTimeImmutable((string) $data['issued_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'   => $this->userId->toString(),
            'password_reset_delivery_id' => $this->passwordResetDeliveryId->toString(),
            'issued_at' => $this->issuedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the target user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the issued delivery-generation identifier.
     */
    public function getPasswordResetDeliveryId(): PasswordResetDeliveryId
    {
        return $this->passwordResetDeliveryId;
    }

    /**
     * Returns when the grant was issued.
     */
    public function getIssuedAt(): DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
