<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates a login attempt that consumers must treat generically.
 */
final class LoginRejectedException extends DomainException
{
}
