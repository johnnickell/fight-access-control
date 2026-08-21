<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Repository;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshSession;

/**
 * Shares durable RefreshSession state across independent in-memory transaction contexts.
 */
final class InMemoryRefreshSessionRepositoryState
{
    /** @var list<RefreshSession> */
    public array $refreshSessions = [];
}
