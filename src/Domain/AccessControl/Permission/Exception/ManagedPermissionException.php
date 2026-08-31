<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Permission\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that managed permission state cannot satisfy an aggregate operation.
 */
final class ManagedPermissionException extends DomainException
{
}
