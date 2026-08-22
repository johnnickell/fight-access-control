<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\PasswordResetGrant\Service;

use DateTimeImmutable;
use Fight\AccessControl\Application\AccessControl\PasswordResetGrant\Service\PasswordResetClock;

final readonly class FixedPasswordResetClock implements PasswordResetClock
{
    public function __construct(private string $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable($this->time);
    }
}
