<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;

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
