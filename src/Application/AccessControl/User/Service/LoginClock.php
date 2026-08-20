<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use DateTimeImmutable;

/**
 * Provides the current time for login session establishment.
 */
interface LoginClock
{
    /**
     * Returns the current login time at invocation.
     */
    public function now(): DateTimeImmutable;
}
