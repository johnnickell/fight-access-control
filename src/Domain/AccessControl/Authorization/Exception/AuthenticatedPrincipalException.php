<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that an authenticated-principal snapshot is invalid.
 */
final class AuthenticatedPrincipalException extends DomainException
{
}
