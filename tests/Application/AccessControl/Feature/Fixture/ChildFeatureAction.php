<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

#[FeatureFlag('child-class')]
#[FeatureFlag('duplicate')]
final class ChildFeatureAction extends ParentFeatureAction
{
    #[FeatureFlag('child-method')]
    #[FeatureFlag('duplicate')]
    public function execute(): string
    {
        return 'direct child execution';
    }

    #[FeatureFlag('child-invokable')]
    public function __invoke(): void
    {
    }
}
