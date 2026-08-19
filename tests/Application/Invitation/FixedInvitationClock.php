<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\Invitation;

use DateTimeImmutable;
use Fight\AccessControl\Application\Invitation\InvitationClock;
use LogicException;

final class FixedInvitationClock implements InvitationClock
{
    /** @var list<DateTimeImmutable> */
    private array $times;

    private int $calls = 0;

    public function __construct(string ...$times)
    {
        $this->times = array_map(static fn (string $time): DateTimeImmutable => new DateTimeImmutable($time), $times);
    }

    public function now(): DateTimeImmutable
    {
        $time = $this->times[$this->calls] ?? null;
        if (!$time instanceof DateTimeImmutable) {
            throw new LogicException('No fixed invitation time remains.');
        }

        ++$this->calls;

        return $time;
    }

    public function calls(): int
    {
        return $this->calls;
    }
}
