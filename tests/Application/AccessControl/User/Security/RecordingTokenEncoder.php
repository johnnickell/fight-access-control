<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Security;

use DateTimeImmutable;
use Fight\Common\Application\Auth\Security\TokenEncoder;

final class RecordingTokenEncoder implements TokenEncoder
{
    /** @var array<string, mixed> */
    public array $claims = [];

    public ?DateTimeImmutable $expiration = null;

    /**
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims, DateTimeImmutable $expiration): string
    {
        $this->claims = $claims;
        $this->expiration = $expiration;

        return 'encoded.jwt.token';
    }
}
