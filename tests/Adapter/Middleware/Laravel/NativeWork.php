<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

final readonly class NativeWork
{
    public function __construct()
    {
        NativeController::$trace->events[] = 'provider';
    }

    public function dispatch(): void
    {
        NativeController::$trace->events[] = 'bus';
    }
}
