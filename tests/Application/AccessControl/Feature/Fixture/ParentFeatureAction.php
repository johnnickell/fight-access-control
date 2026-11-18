<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;
use RuntimeException;

#[FeatureFlag('parent-class')]
#[FeatureFlag('duplicate')]
abstract class ParentFeatureAction
{
    public function __construct()
    {
        throw new RuntimeException('Metadata inspection must not construct controllers.');
    }

    #[FeatureFlag('parent-method')]
    #[FeatureFlag('duplicate')]
    public function execute(): string
    {
        return 'direct execution';
    }

    #[FeatureFlag('inherited-method')]
    public function inherited(): void
    {
    }

    protected function protectedAction(): void
    {
    }

    #[FeatureFlag('parent-invokable')]
    public function __invoke(): void
    {
    }
}
