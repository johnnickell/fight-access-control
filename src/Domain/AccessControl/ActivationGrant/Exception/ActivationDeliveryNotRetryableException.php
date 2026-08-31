<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that activation delivery work cannot be invoked again.
 */
final class ActivationDeliveryNotRetryableException extends DomainException
{
}
