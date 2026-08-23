<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\PasswordResetGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that password-reset delivery state violates its aggregate invariants.
 */
final class PasswordResetDeliveryException extends DomainException
{
}
