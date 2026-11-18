<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Adapter\Http\CodeIgniter;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use LogicException;

#[FeatureFlag('rollout')]
#[FeatureFlag('rollout')]
class FeatureParent
{
    public function __construct()
    {
        throw new LogicException('Metadata selection must not construct a controller.');
    }

    #[FeatureFlag('parent-gate')]
    public function handle(): void
    {
        throw new LogicException('Admission must not execute the selected action.');
    }

    public function __invoke(): void
    {
        throw new LogicException('Admission must not execute the selected action.');
    }
}
