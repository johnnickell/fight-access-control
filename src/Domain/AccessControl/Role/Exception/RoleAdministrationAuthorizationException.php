<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Raised when an actor cannot administer custom roles.
 */
final class RoleAdministrationAuthorizationException extends DomainException
{
}
