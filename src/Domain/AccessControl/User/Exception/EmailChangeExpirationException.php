<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class EmailChangeExpirationException
 *
 * Reports a rejected email-change expiry transition.
 */
final class EmailChangeExpirationException extends DomainException
{
}
