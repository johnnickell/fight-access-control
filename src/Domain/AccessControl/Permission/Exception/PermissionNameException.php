<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that a permission name is not canonical.
 */
final class PermissionNameException extends DomainException
{
}
