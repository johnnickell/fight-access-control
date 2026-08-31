<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeCredentialGenerator;
use Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\EmailChangeCredential;

final readonly class FixedEmailChangeCredentialGenerator implements EmailChangeCredentialGenerator
{
    public function __construct(private string $credential)
    {
    }

    public function generate(): EmailChangeCredential
    {
        return EmailChangeCredential::fromString($this->credential);
    }
}
