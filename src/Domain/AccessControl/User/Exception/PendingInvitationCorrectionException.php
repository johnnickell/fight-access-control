<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports a rejected pending-invitation correction.
 */
final class PendingInvitationCorrectionException extends DomainException
{
}
