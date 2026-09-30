<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureStateException
 *
 * Rejects invalid persisted Feature state.
 */
final class FeatureStateException extends DomainException
{
}
