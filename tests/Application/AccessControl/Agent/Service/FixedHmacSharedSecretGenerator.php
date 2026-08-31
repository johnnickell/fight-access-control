<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Service\HmacSharedSecretGenerator;

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
