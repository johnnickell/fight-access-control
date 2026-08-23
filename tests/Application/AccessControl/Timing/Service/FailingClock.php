<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Timing\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;
use Throwable;

final readonly class FailingClock implements Clock
{
    public function __construct(private Throwable $failure)
    {
    }

    public function now(): DateTimeImmutable
    {
        throw $this->failure;
    }
}
