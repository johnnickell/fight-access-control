<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\Service;

use DateTimeImmutable;

/**
 * Provides the current time for refresh-session operations.
 */
interface RefreshSessionClock
{
    /**
     * Returns the current refresh-session time.
     */
    public function now(): DateTimeImmutable;
}
