<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;

/**
 * Generates raw email-change confirmation credentials.
 */
interface EmailChangeCredentialGenerator
{
    /**
     * Generates one credential for immediate hashing and encryption.
     */
    public function generate(): EmailChangeCredential;
}
