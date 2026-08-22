<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that a replacement activation generation cannot be issued.
 */
final class ActivationDeliveryNotResendableException extends DomainException
{
}
