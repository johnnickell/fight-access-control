<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\ActivationGrant\Service;

use Fight\AccessControl\Application\AccessControl\ActivationGrant\Service\ActivationCredentialGenerator;
use Fight\AccessControl\Domain\AccessControl\ActivationGrant\ActivationCredential;

final readonly class FixedCredentialGenerator implements ActivationCredentialGenerator
{
    public function __construct(private string $credential)
    {
    }

    public function generate(): ActivationCredential
    {
        return ActivationCredential::fromString($this->credential);
    }
}
