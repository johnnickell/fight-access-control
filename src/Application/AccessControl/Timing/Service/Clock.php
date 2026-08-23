<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Timing\Service;

use DateTimeImmutable;

/**
 * Provides the current time for application operations.
 */
interface Clock
{
    /**
     * Returns the current time at invocation.
     */
    public function now(): DateTimeImmutable;
}
