<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports a rejected account-lifecycle transition.
 */
final class UserLifecycleException extends DomainException
{
}
