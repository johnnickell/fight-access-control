<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use Fight\AccessControl\Application\AccessControl\User\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Domain\AccessControl\User\PasswordResetCredential;

final readonly class FixedPasswordResetCredentialGenerator implements PasswordResetCredentialGenerator
{
    public function __construct(private string $credential)
    {
    }

    public function generate(): PasswordResetCredential
    {
        return PasswordResetCredential::fromString($this->credential);
    }
}
