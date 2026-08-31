<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports denied User role-assignment administration.
 */
final class UserRoleAssignmentAuthorizationException extends DomainException
{
}
