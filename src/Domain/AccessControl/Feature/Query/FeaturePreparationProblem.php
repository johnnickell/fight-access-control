<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Query;

/**
 * Enum FeaturePreparationProblem
 *
 * Classifies configuration that prevents preparation without treating storage failures as missing data.
 */
enum FeaturePreparationProblem: string
{
    case MISSING_FEATURE = 'missing_feature';
    case BROKEN_BINDING = 'broken_binding';
    case INVALID_DEFINITION = 'invalid_definition';
}
