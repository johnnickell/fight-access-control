<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Adapter\Middleware\Laravel\FeatureMiddleware;
use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

#[FeatureFlag('rollout')]
final class FeatureLegacyController extends Controller
{
    public function __construct()
    {
        ControllerWithFormRequest::$trace->events[] = 'construct';
        $this->middleware(FeatureMiddleware::class);
    }

    public function __invoke(): Response
    {
        ControllerWithFormRequest::$trace->events[] = 'action';

        return new Response('', 204);
    }
}
