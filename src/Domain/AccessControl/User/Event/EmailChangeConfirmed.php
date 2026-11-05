<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class EmailChangeConfirmed
 *
 * Records an email-change confirmation after durable commit.
 */
final readonly class EmailChangeConfirmed implements Event
{
    /**
     * Constructs EmailChangeConfirmed
     *
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
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString($data['user_id']),
            CredentialDeliveryTimestamp::fromString($data['confirmed_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical email-confirmation fact
     *
     * @phpstan-return array{user_id: string, confirmed_at: string}
     */
    public function toArray(): array
    {
        return [
            'user_id'      => $this->userId->toString(),
            'confirmed_at' => $this->confirmedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the changed user
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns when confirmation committed
     */
    public function getConfirmedAt(): DateTimeImmutable
    {
        return $this->confirmedAt;
    }
}
