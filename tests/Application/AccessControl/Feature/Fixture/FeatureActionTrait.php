<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Fixture;

use Fight\AccessControl\Application\AccessControl\Feature\Attribute\FeatureFlag;

trait FeatureActionTrait
{
    #[FeatureFlag('trait-method')]
    public static function traitAction(): void
    {
    }
}
