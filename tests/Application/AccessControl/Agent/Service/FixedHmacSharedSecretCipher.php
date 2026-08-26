<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretCipher;
use SensitiveParameter;

final readonly class FixedHmacSharedSecretCipher implements HmacSharedSecretCipher
{
    public function __construct(private string $prefix)
    {
    }

    public function encrypt(#[SensitiveParameter] string $hmacSharedSecret): string
    {
        return $this->prefix.$hmacSharedSecret;
    }
}
