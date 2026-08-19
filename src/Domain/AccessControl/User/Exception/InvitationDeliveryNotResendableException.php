<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that a replacement activation delivery cannot be staged.
 */
final class InvitationDeliveryNotResendableException extends DomainException
{
}
