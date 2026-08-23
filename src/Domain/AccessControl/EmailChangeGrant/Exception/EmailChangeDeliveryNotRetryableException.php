<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports delivery work that can no longer be invoked safely.
 */
final class EmailChangeDeliveryNotRetryableException extends DomainException
{
}
