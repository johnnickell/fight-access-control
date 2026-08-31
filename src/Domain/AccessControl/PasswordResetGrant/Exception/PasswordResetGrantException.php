<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that password-reset authority cannot satisfy a requested transition.
 */
final class PasswordResetGrantException extends DomainException
{
}
