<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Event;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Announces that one authoritative refresh session has been revoked.
 */
final readonly class CurrentSessionLoggedOut implements Event
{
    /**
     * Creates the safe current-session logout outcome.
     */
    public function __construct(private RefreshSessionId $refreshSessionId)
    {
    }

    /**
     * @inheritDoc
     */
    public static function fromArray(array $data): static
    {
        if (!array_key_exists('refresh_session_id', $data)) {
            throw new DomainException('Missing required key "refresh_session_id" in data array');
        }

        return new static(RefreshSessionId::fromString((string) $data['refresh_session_id']));
    }

    /**
     * @inheritDoc
     */
    public function toArray(): array
    {
        return ['refresh_session_id' => $this->refreshSessionId->toString()];
    }

    /**
     * Returns the revoked authoritative refresh session.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }
}
