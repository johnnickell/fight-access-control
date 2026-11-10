<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\Symfony\EventSubscriber;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Symfony\Component\HttpFoundation\Response;

#[FeatureFlag('child')]
final class FeatureChildController extends FeatureParentController
{
    #[FeatureFlag('rollout')]
    public function handle(): Response
    {
        return parent::handle();
    }
}
