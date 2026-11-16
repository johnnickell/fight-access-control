<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\FeatureMiddleware;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Illuminate\Routing\Controllers\HasMiddleware;
use Symfony\Component\HttpFoundation\Response;

#[FeatureFlag('rollout')]
final class FeatureStaticController implements HasMiddleware
{
    public function __construct()
    {
        ControllerWithFormRequest::$trace->events[] = 'construct';
    }

    /** @return list<class-string> */
    public static function middleware(): array
    {
        ControllerWithFormRequest::$trace->events[] = 'discovery';

        return [FeatureMiddleware::class];
    }

    public function __invoke(): Response
    {
        ControllerWithFormRequest::$trace->events[] = 'action';

        return new Response('', 204);
    }
}
