<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use DateTimeImmutable;

/**
 * Provides the current time for invitation issuance.
 */
interface InvitationClock
{
    /**
     * Returns the current time at invocation.
     */
    public function now(): DateTimeImmutable;
}
