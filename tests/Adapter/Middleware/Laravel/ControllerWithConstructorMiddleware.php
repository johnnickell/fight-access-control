<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\PermissionMiddleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class ControllerWithConstructorMiddleware extends Controller
{
    public function __construct()
    {
        ControllerWithFormRequest::$trace->events[] = 'construct';
        $this->middleware([PermissionMiddleware::class, RecordingValidationMiddleware::class]);
    }

    public function __invoke(Request $request): Response
    {
        ControllerWithFormRequest::$trace->events[] = 'action';

        return new Response('', 204);
    }
}
