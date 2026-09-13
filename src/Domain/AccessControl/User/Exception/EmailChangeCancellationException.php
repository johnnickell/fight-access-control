<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class EmailChangeCancellationException
 *
 * Reports a rejected email-change cancellation.
 */
final class EmailChangeCancellationException extends DomainException
{
}
