<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class CustomRoleException
 *
 * Reports a rejected custom-role operation.
 */
class CustomRoleException extends DomainException
{
}
