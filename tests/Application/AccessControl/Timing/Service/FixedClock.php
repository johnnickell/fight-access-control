<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Timing\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\Timing\Service\Clock;

final class FixedClock implements Clock
{
    /** @var list<DateTimeImmutable> */
    private readonly array $times;

    private int $calls = 0;

    public function __construct(DateTimeImmutable|string ...$times)
    {
        $this->times = array_map(
            static fn (DateTimeImmutable|string $time): DateTimeImmutable =>
                $time instanceof DateTimeImmutable ? $time : new DateTimeImmutable($time),
            $times
        );
    }

    public function now(): DateTimeImmutable
    {
        $index = $this->calls;
        if ($index >= count($this->times)) {
            $index = count($this->times) - 1;
        }

        ++$this->calls;

        return $this->times[$index];
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
