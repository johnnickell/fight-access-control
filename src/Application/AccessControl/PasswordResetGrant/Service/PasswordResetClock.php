<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use DateTimeImmutable;

/**
 * Provides the current time for password-reset issuance.
 */
interface PasswordResetClock
{
    /**
     * Returns the current time at invocation.
     */
    public function now(): DateTimeImmutable;
}
