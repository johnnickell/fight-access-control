<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;

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
