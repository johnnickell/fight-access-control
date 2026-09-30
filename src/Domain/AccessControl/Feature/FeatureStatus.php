<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature;

/**
 * Enum FeatureStatus
 *
 * Defines the persisted availability setting without evaluating principal authority.
 */
enum FeatureStatus: string
{
    case OFF = 'off';
    case PREVIEW = 'preview';
    case ON = 'on';
}
