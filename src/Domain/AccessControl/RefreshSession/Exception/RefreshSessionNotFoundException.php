<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class RefreshSessionNotFoundException
 *
 * Raised when an authoritative refresh session cannot be found.
 */
final class RefreshSessionNotFoundException extends DomainException
{
}
