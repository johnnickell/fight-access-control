<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Middleware\Laravel;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use Symfony\Component\HttpFoundation\Response;

#[FeatureFlag('parent')]
class FeatureParentController
{
    #[FeatureFlag('overridden')]
    public function handle(string $id): Response
    {
        return new Response('', 204);
    }
}
