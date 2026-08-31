<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Raised when an administrative session revocation omits its required reason.
 */
final class SessionRevocationReasonRequiredException extends DomainException
{
}
