<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Command;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetDeliveryId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Command\Command;

/**
 * Class ExpirePasswordResetDelivery
 *
 * Processes terminal expiry for password-reset delivery work.
 */
final readonly class ExpirePasswordResetDelivery implements Command
{
    /**
     * Constructs ExpirePasswordResetDelivery
     *
     * Creates the terminal-expiry command.
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

            if (!is_string($data[$key])) {
                throw new DomainException(sprintf('Invalid type for "%s" in data array', $key));
            }
        }

        return new static(
            $data['actor_id'],
            UserId::fromString($data['user_id']),
            PasswordResetDeliveryId::fromString($data['password_reset_delivery_id']),
            CredentialDeliveryTimestamp::fromString($data['occurred_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical expiry command payload
     *
     * @phpstan-return array{actor_id: string, user_id: string, password_reset_delivery_id: string, occurred_at: string}
     */
    public function toArray(): array
    {
        return [
            'actor_id'                   => $this->actorId,
            'user_id'                    => $this->userId->toString(),
            'password_reset_delivery_id' => $this->passwordResetDeliveryId->toString(),
            'occurred_at'                => $this->occurredAt->format('Y-m-d\TH:i:s.uP')
        ];
    }

    /**
     * Returns the actor processing terminal expiry
     */
    public function getActorId(): string
    {
        return $this->actorId;
    }

    /**
     * Returns the target user identifier
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

    /**
     * Returns when terminal expiry was processed
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
