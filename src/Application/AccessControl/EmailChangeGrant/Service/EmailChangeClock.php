<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use DateTimeImmutable;

/**
 * Supplies authoritative email-change lifecycle time.
 */
interface EmailChangeClock
{
    /**
     * Returns the current time at invocation.
     */
    public function now(): DateTimeImmutable;
}
