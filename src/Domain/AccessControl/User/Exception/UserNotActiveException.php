<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that an operation requires an active identity with an established password.
 */
final class UserNotActiveException extends DomainException
{
}
