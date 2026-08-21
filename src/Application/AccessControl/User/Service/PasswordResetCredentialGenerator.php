<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Domain\AccessControl\User\PasswordResetCredential;

/**
 * Generates raw password-reset credentials at the application boundary.
 */
interface PasswordResetCredentialGenerator
{
    /**
     * Generates one raw credential for immediate hashing and encryption.
     */
    public function generate(): PasswordResetCredential;
}
