<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records an email-change confirmation after durable commit.
 */
final readonly class EmailChangeConfirmed implements Event
{
    /**
     * Constructs the secret-free email-change confirmation event.
     */
    public function __construct(
        private UserId $userId,
        private DateTimeImmutable $confirmedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'confirmed_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            new DateTimeImmutable((string) $data['confirmed_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId->toString(),
            'confirmed_at' => $this->confirmedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the changed user.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when confirmation committed.
     */
    public function getConfirmedAt(): DateTimeImmutable
    {
        return $this->confirmedAt;
    }
}
