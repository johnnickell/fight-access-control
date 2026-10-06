<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureDiscoveryException
 *
 * Indicates that a complete inventory for the required code scope is unavailable.
 */
final class FeatureDiscoveryException extends DomainException
{
}
