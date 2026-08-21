<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records a completed password reset after its durable state committed.
 */
final readonly class PasswordResetCompleted implements Event
{
    /**
     * Constructs the secret-free password-reset completion event.
     */
    public function __construct(
        private UserId $userId,
        private DateTimeImmutable $completedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'completed_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            new DateTimeImmutable((string) $data['completed_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'      => $this->userId->toString(),
            'completed_at' => $this->completedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the reset user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when the password reset completed.
     */
    public function getCompletedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }
}
