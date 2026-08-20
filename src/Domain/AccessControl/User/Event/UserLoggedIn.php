<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Announces an established authoritative session without exposing credentials.
 */
final readonly class UserLoggedIn implements Event
{
    /**
     * Creates the safe login outcome.
     */
    public function __construct(
        private UserId $userId,
        private RefreshSessionId $refreshSessionId,
        private DateTimeImmutable $loggedInAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['user_id', 'refresh_session_id', 'logged_in_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                $message = sprintf('Missing required key "%s" in data array', $key);
                throw new DomainException($message);
            }
        }

        return new static(
            UserId::fromString((string) $data['user_id']),
            RefreshSessionId::fromString((string) $data['refresh_session_id']),
            new DateTimeImmutable((string) $data['logged_in_at'])
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
            'logged_in_at'       => $this->loggedInAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the authenticated identity.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the authoritative session identifier.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns when authentication completed.
     */
    public function getLoggedInAt(): DateTimeImmutable
    {
        return $this->loggedInAt;
    }
}
