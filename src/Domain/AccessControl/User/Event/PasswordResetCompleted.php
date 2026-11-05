<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class PasswordResetCompleted
 *
 * Records a completed password reset after its durable state committed.
 */
final readonly class PasswordResetCompleted implements Event
{
    /**
     * Constructs PasswordResetCompleted
     *
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
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString($data['user_id']),
            CredentialDeliveryTimestamp::fromString($data['completed_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical password-reset fact
     *
     * @phpstan-return array{user_id: string, completed_at: string}
     */
    public function toArray(): array
    {
        return [
            'user_id'      => $this->userId->toString(),
            'completed_at' => $this->completedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the reset user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when the password reset completed
     */
    public function getCompletedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }
}
