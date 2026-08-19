<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Domain\AccessControl\User\ActivationCredential;

/**
 * Generates raw activation credentials at the application boundary.
 */
interface ActivationCredentialGenerator
{
    /**
     * Generates one raw credential for immediate hashing and encryption.
     */
    public function generate(): ActivationCredential;
}
