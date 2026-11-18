<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Feature\Admission;

use RuntimeException;

/**
 * Class FeatureUnavailable
 *
 * Identifies ordinary unavailability only, never a missing definition or failed binding.
 */
final class FeatureUnavailable extends RuntimeException
{
}
