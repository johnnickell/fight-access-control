<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that an identity cannot transition through activation from its current state.
 */
final class UserNotPendingActivationException extends DomainException
{
}
