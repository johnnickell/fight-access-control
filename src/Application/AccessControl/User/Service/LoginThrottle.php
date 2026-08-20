<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\Common\Domain\Value\Internet\EmailAddress;

/**
 * Applies consumer-owned bounded throttling before credential verification.
 */
interface LoginThrottle
{
    /**
     * Determines whether one login attempt is permitted.
     */
    public function allows(EmailAddress $email): bool;
}
