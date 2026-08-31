<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\RefreshSession\Service;

use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;

/**
 * Generates opaque refresh credentials using consumer-owned cryptography.
 */
interface RefreshCredentialGenerator
{
    /**
     * Generates one unpredictable refresh credential.
     */
    public function generate(): RefreshCredential;
}
