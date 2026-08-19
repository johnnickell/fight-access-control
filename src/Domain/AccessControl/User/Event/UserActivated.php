<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Records an invited identity activation after its durable state committed.
 */
final readonly class UserActivated implements Event
{
    /**
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
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            RefreshSessionId::fromString((string) $data['refresh_session_id']),
            new DateTimeImmutable((string) $data['activated_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'user_id'            => $this->userId->toString(),
            'refresh_session_id' => $this->refreshSessionId->toString(),
            'activated_at'       => $this->activatedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the activated user identifier.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the first refresh-session identifier.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns when the account became active.
     */
    public function getActivatedAt(): DateTimeImmutable
    {
        return $this->activatedAt;
    }
}
