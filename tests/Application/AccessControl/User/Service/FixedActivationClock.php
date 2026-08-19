<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\User\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\User\Service\ActivationClock;

final readonly class FixedActivationClock implements ActivationClock
{
    public function __construct(private DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
