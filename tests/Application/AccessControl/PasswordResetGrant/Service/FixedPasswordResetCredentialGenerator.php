<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetCredentialGenerator;
use Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\PasswordResetCredential;

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
