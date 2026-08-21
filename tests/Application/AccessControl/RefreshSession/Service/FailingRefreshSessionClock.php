<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\RefreshSession\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\RefreshSession\Service\RefreshSessionClock;
use Throwable;

final readonly class FailingRefreshSessionClock implements RefreshSessionClock
{
    public function __construct(private Throwable $failure)
    {
    }

    public function now(): DateTimeImmutable
    {
        throw $this->failure;
    }
}
