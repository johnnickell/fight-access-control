<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

#[FeatureFlag('interface-class')]
interface FeatureActionContract
{
    #[FeatureFlag('interface-method')]
    public function execute(): string;
}
