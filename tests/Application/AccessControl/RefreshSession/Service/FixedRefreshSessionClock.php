<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshSessionClock;

final readonly class FixedRefreshSessionClock implements RefreshSessionClock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
