<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records an authenticated password change after its durable state committed.
 */
final readonly class PasswordChanged implements Event
{
    /**
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
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            new DateTimeImmutable((string) $data['changed_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId->toString(),
            'changed_at' => $this->changedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the changed user's identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when the password changed.
     */
    public function getChangedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }
}
