<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\CredentialDelivery\CredentialDeliveryTimestamp;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class UserActivated
 *
 * Records an invited identity activation after its durable state committed.
 */
final readonly class UserActivated implements Event
{
    /**
     * Constructs UserActivated
     *
     * Constructs the account-activation event.
     */
    public function __construct(
        private UserId $userId,
        private RefreshSessionId $refreshSessionId,
        private DateTimeImmutable $activatedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'refresh_session_id', 'activated_at'] as $key) {
            if (!array_key_exists($key, $data) || !is_string($data[$key])) {
                $message = sprintf('Missing or invalid string "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString($data['user_id']),
            RefreshSessionId::fromString($data['refresh_session_id']),
            CredentialDeliveryTimestamp::fromString($data['activated_at'])->toDateTimeImmutable()
        );
    }

    /**
     * Returns the canonical activation fact
     *
     * @phpstan-return array{user_id: string, refresh_session_id: string, activated_at: string}
     */
    public function toArray(): array
    {
        return [
            'user_id'            => $this->userId->toString(),
            'refresh_session_id' => $this->refreshSessionId->toString(),
            'activated_at'       => $this->activatedAt->format(DATE_ATOM)
        ];
    }

    /**
     * Returns the activated user identifier
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the first refresh-session identifier
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns when the account became active
     */
    public function getActivatedAt(): DateTimeImmutable
    {
        return $this->activatedAt;
    }
}
