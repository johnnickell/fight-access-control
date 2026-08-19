<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use Fight\AccessControl\Application\Invitation\ActivationCredentialGenerator;

final readonly class FixedCredentialGenerator implements ActivationCredentialGenerator
{
    public function __construct(private string $credential)
    {
    }

    public function generate(): string
    {
        return $this->credential;
    }
}
