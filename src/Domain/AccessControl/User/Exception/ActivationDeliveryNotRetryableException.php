<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that activation delivery work no longer retains recoverable credential material.
 */
final class ActivationDeliveryNotRetryableException extends DomainException
{
}
