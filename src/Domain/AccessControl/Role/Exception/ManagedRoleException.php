<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that managed role state cannot satisfy an aggregate operation.
 */
final class ManagedRoleException extends DomainException
{
}
