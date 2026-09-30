<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Feature\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class FeatureConflictException
 *
 * Rejects a duplicate identity or name so the caller can retry against fresh storage.
 */
final class FeatureConflictException extends DomainException
{
}
