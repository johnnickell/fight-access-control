<?php

declare(strict_types=1);

namespace Fight\Test\AccessControl\Application\AccessControl\Feature\Conformance;

use Fight\AccessControl\Application\AccessControl\Feature\CommandHandler\ProvisionFeaturesHandler;
use Fight\AccessControl\Application\AccessControl\Feature\QueryHandler\ValidateFeaturePreparationHandler;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(ProvisionFeaturesHandler::class)]
#[CoversClass(ValidateFeaturePreparationHandler::class)]
final class InMemoryFeaturePreparationConformanceTest extends FeaturePreparationConformance
{
    protected function environment(): FeaturePreparationEnvironment
    {
        return new ControlledPreparationEnvironment();
    }
}
