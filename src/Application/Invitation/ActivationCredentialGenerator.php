<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\Invitation;

/**
 * Generates raw activation credentials at the application boundary.
 */
interface ActivationCredentialGenerator
{
    /**
     * Generates one raw credential for immediate hashing and encryption.
     */
    public function generate(): string;
}
