<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\EmailChangeGrant\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\EmailChangeGrant\Service\EmailChangeClock;

final readonly class FixedEmailChangeClock implements EmailChangeClock
{
    public function __construct(private string $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->time);
    }
}
