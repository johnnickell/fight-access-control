<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use DateTimeImmutable;

/**
 * Provides the current time for account activation.
 */
interface ActivationClock
{
    /**
     * Returns the current activation time at invocation.
     */
    public function now(): DateTimeImmutable;
}
