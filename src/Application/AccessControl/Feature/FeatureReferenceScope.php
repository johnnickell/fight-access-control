<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature;

/**
 * Enum FeatureReferenceScope
 *
 * Separates candidate-code preparation from current-code reference checks.
 */
enum FeatureReferenceScope: string
{
    case CANDIDATE = 'candidate';
    case CURRENT = 'current';
}
