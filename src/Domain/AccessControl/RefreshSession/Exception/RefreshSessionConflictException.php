<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Raised when concurrent mutation supersedes the selected refresh session.
 */
final class RefreshSessionConflictException extends DomainException
{
}
