<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession;

use Exception;

/**
 * Provides authoritative refresh-session persistence.
 */
interface RefreshSessionRepository
{
    /**
     * Adds a refresh session.
     *
     * @throws Exception When an error occurs
     */
    public function add(RefreshSession $refreshSession): void;

    /**
     * Retrieves a refresh session by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(RefreshSessionId $id): ?RefreshSession;
}
