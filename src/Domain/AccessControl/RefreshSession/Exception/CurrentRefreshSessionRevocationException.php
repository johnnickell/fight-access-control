<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Raised when self-service revocation targets the session making the request.
 */
final class CurrentRefreshSessionRevocationException extends DomainException
{
}
