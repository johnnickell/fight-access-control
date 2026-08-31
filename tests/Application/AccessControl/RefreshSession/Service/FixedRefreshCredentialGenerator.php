<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service;

use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshCredentialGenerator;
use Fight\AccessControl\Domain\AccessControl\RefreshSession\RefreshCredential;

final readonly class FixedRefreshCredentialGenerator implements RefreshCredentialGenerator
{
    public function __construct(private RefreshCredential $refreshCredential)
    {
    }

    public function generate(): RefreshCredential
    {
        return $this->refreshCredential;
    }
}
