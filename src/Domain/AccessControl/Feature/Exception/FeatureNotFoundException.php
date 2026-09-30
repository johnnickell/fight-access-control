<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureNotFoundException
 *
 * Signals an authoritatively absent Feature, not ordinary unavailability.
 */
final class FeatureNotFoundException extends DomainException
{
}
