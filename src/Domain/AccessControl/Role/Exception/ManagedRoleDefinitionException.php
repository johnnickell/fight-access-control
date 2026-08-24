<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that a managed role definition is invalid.
 */
final class ManagedRoleDefinitionException extends DomainException
{
}
