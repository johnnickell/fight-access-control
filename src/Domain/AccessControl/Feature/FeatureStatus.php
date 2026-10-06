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

    /**
     * Returns whether this setting admits the captured testing Permission
     *
     * Availability does not grant action authorization.
     */
    public function isAvailable(bool $hasPermission): bool
    {
        return match ($this) {
            self::OFF => false,
            self::PREVIEW => $hasPermission,
            self::ON => true
        };
    }
}
