<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretDecipher;

final readonly class FixedHmacSharedSecretDecipher implements HmacSharedSecretDecipher
{
    public function __construct(private string $prefix)
    {
    }

    public function decrypt(string $encryptedHmacSharedSecretEnvelope): string
    {
        return substr($encryptedHmacSharedSecretEnvelope, strlen($this->prefix));
    }
}
