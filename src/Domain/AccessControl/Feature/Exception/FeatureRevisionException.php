<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureRevisionException
 *
 * Rejects an edit against an obsolete or invalid Feature revision.
 */
final class FeatureRevisionException extends DomainException
{
}
