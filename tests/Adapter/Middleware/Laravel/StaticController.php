<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Symfony\Component\HttpFoundation\Response;

final class StaticController implements HasMiddleware
{
    public function __construct()
    {
        NativeController::$trace->events[] = 'construct';
    }

    /** @return list<class-string> */
    public static function middleware(): array
    {
        return [PermissionMiddleware::class, NativeValidator::class];
    }

    public function __invoke(Request $request): Response
    {
        NativeController::$trace->events[] = 'action';

        return new Response('', 204);
    }
}
