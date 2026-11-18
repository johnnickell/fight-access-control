<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

#[FeatureFlag('child-gate')]
final class FeatureController extends FeatureParent
{
    #[FeatureFlag('method-gate')]
    public function handle(): void
    {
        parent::handle();
    }
}
