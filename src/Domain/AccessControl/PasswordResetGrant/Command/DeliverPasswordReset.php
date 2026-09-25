<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class DeliverPasswordReset
 *
 * Invokes one exact password-reset delivery generation.
 */
final readonly class DeliverPasswordReset implements Command
{
    /**
     * Constructs DeliverPasswordReset
     */
    public function __construct(
        private string $actorId,
        private UserId $userId,
        private PasswordResetDeliveryId $passwordResetDeliveryId
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'password_reset_delivery_id'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            (string) $data['actor_id'],
            UserId::fromString((string) $data['user_id']),
            PasswordResetDeliveryId::fromString((string) $data['password_reset_delivery_id'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id'                   => $this->actorId,
            'user_id'                    => $this->userId->toString(),
            'password_reset_delivery_id' => $this->passwordResetDeliveryId->toString()
        ];
    }

    /**
     * Returns the actor that caused delivery invocation
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the owning User identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the exact delivery-generation identifier
     */
    public function getPasswordResetDeliveryId(): PasswordResetDeliveryId
    {
        return $this->passwordResetDeliveryId;
    }
}
