<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class PasswordChanged
 *
 * Records an authenticated password change after its durable state committed.
 */
final readonly class PasswordChanged implements Event
{
    /**
     * Constructs PasswordChanged
     *
     * Constructs the secret-free password-change event.
     */
    public function __construct(
        private UserId $userId,
        private DateTimeImmutable $changedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'changed_at'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString($data['user_id']),
            CredentialDeliveryTimestamp::fromString($data['changed_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical password-change fact
     *
     * @phpstan-return array{user_id: string, changed_at: string}
     */
    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId->toString(),
            'changed_at' => $this->changedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the changed user's identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when the password changed
     */
    public function getChangedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }
}
