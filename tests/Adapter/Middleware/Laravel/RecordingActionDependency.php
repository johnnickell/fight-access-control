<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

final readonly class RecordingActionDependency
{
    public function __construct()
    {
        ControllerWithFormRequest::$trace->events[] = 'provider';
    }

    public function dispatch(): void
    {
        ControllerWithFormRequest::$trace->events[] = 'bus';
    }
}
