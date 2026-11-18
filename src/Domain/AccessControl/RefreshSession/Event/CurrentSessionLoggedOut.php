<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Event;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Event\Event;

/**
 * Class CurrentSessionLoggedOut
 *
 * Announces that one authoritative refresh session has been revoked.
 */
final readonly class CurrentSessionLoggedOut implements Event
{
    /**
     * Constructs CurrentSessionLoggedOut
     *
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
        if (!isset($data['refresh_session_id']) || !is_string($data['refresh_session_id'])) {
            throw new DomainException('Missing or invalid refresh-session ID.');
        }

        return new static(RefreshSessionId::fromString($data['refresh_session_id']));
    }

    /**
     * Returns the canonical logout fact
     *
     * @phpstan-return array{refresh_session_id: string}
     */
    public function toArray(): array
    {
        return ['refresh_session_id' => $this->refreshSessionId->toString()];
    }

    /**
     * Returns the revoked authoritative refresh session
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }
}
