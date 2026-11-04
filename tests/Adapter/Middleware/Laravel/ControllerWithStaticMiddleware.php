<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Symfony\Component\HttpFoundation\Response;

final class ControllerWithStaticMiddleware implements HasMiddleware
{
    public function __construct()
    {
        ControllerWithFormRequest::$trace->events[] = 'construct';
    }

    /** @return list<class-string> */
    public static function middleware(): array
    {
        return [PermissionMiddleware::class, RecordingValidationMiddleware::class];
    }

    public function __invoke(Request $request): Response
    {
        ControllerWithFormRequest::$trace->events[] = 'action';

        return new Response('', 204);
    }
}
