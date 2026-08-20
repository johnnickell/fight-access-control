<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use DateTimeImmutable;

/**
 * Provides the current time for synchronous authentication operations.
 */
interface AuthenticationClock
{
    /**
     * Returns the current authentication time.
     */
    public function now(): DateTimeImmutable;
}
