<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\CommandHandler;

use Fight\AccessControl\Application\AccessControl\User\ActivationCredentialGenerator;

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
