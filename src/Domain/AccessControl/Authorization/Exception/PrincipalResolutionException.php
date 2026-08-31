<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that current authoritative principal state cannot be resolved safely.
 */
final class PrincipalResolutionException extends DomainException
{
}
