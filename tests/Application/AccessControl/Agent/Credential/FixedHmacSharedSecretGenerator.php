<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Credential;

use Fight\AccessControl\Application\AccessControl\Agent\Credential\HmacSharedSecretGenerator;

final readonly class FixedHmacSharedSecretGenerator implements HmacSharedSecretGenerator
{
    public function __construct(private string $hmacSharedSecret)
    {
    }

    public function generate(): string
    {
        return $this->hmacSharedSecret;
    }
}
