<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature;

use Fight\Common\Domain\Identity\UniqueId;

/**
 * Class FeatureId
 *
 * Represents a stable Feature identity, not a permanent reservation of its name.
 */
final readonly class FeatureId extends UniqueId
{
}
