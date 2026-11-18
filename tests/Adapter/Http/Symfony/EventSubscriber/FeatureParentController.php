<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Symfony\Component\HttpFoundation\Response;

#[FeatureFlag('rollout')]
class FeatureParentController
{
    public function __construct()
    {
        NativeController::$trace->events[] = 'construct';
    }

    #[FeatureFlag('inherited')]
    #[FeatureFlag('rollout')]
    public function handle(): Response
    {
        NativeController::$trace->events[] = 'action';

        return new Response('', 204);
    }
}
