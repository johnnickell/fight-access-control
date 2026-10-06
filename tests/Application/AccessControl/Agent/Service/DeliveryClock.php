<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;

final class DeliveryClock implements Clock
{
    public DateTimeImmutable $time;

    public function __construct()
    {
        $this->time = new DateTimeImmutable('2026-09-27T12:00:00+00:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }

    public function advance(int $seconds): void
    {
        $this->time = $this->time->modify('+'.$seconds.' seconds');
    }
}
