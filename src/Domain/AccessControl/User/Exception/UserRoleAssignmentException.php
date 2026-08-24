<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports an invalid User role-assignment transition.
 */
final class UserRoleAssignmentException extends DomainException
{
}
