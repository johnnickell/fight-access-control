<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Symfony\Component\HttpFoundation\Response;

#[FeatureFlag('child')]
#[FeatureFlag('parent')]
final class FeatureChildController extends FeatureParentController
{
    #[FeatureFlag('method')]
    #[FeatureFlag('method')]
    public function handle(string $id): Response
    {
        ControllerWithFormRequest::$trace->events[] = 'action';

        return parent::handle($id);
    }
}
