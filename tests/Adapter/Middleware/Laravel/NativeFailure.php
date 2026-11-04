<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class NativeFailure extends RuntimeException
{
    public function report(): void
    {
        NativeController::$trace->events[] = 'raw-exception-report';
    }

    /** @return array<string, string> */
    public function context(): array
    {
        NativeController::$trace->events[] = 'raw-exception-context';

        return ['private' => 'PRIVATE CONTEXT'];
    }

    public function render(Request $request): Response
    {
        NativeController::$trace->events[] = 'delegated-exception-render';

        return new Response('Consumer HTML sentinel', 409, ['Content-Type' => 'text/html']);
    }
}
