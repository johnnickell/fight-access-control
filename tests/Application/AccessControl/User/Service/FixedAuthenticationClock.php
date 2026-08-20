<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Service\AuthenticationClock;

final readonly class FixedAuthenticationClock implements AuthenticationClock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
