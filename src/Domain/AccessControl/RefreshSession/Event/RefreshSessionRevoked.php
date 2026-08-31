<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Event;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Announces safe evidence that an active refresh session was revoked.
 */
final readonly class RefreshSessionRevoked implements Event
{
    /**
     * Creates the safe session-revocation outcome.
     */
    public function __construct(
        private UserId $actorId,
        private UserId $userId,
        private RefreshSessionId $refreshSessionId,
        private DateTimeImmutable $revokedAt
    ) {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        foreach (['actor_id', 'user_id', 'refresh_session_id', 'revoked_at'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new DomainException(sprintf('Missing required key "%s" in data array', $key));
            }
        }

        return new static(
            UserId::fromString((string) $data['actor_id']),
            UserId::fromString((string) $data['user_id']),
            RefreshSessionId::fromString((string) $data['refresh_session_id']),
            new DateTimeImmutable((string) $data['revoked_at'])
        );
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return [
            'actor_id' => $this->actorId->toString(),
            'user_id' => $this->userId->toString(),
            'refresh_session_id' => $this->refreshSessionId->toString(),
            'revoked_at' => $this->revokedAt->format(DATE_ATOM),
        ];
    }

    /**
     * Returns the user who requested revocation.
     */
    public function getActorId(): UserId
    {
        return $this->actorId;
    }

    /**
     * Returns the user who owned the revoked session.
     */
    public function getUserId(): UserId
    {
        return $this->userId;
    }

    /**
     * Returns the revoked refresh session.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }

    /**
     * Returns when the revocation was authorized.
     */
    public function getRevokedAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
