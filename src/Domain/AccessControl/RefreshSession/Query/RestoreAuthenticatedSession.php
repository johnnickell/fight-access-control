<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Query;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSessionId;
use Fight\Common\Domain\Exception\DomainException;
use Fight\Common\Domain\Messaging\Query\Query;

/**
 * Queries the authoritative state needed to restore an authenticated session.
 */
final readonly class RestoreAuthenticatedSession implements Query
{
    /**
     * Constructs the authenticated-session restoration query.
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
     * Returns the authoritative refresh session to restore.
     */
    public function getRefreshSessionId(): RefreshSessionId
    {
        return $this->refreshSessionId;
    }
}
