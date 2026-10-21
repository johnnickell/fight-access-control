<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Role\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class RoleRevisionException
 *
 * Reports invalid or exhausted Role revision state
 */
final class RoleRevisionException extends DomainException
{
}
