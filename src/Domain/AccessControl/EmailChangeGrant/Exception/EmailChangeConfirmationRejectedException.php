<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports a generically rejected email-change confirmation.
 */
final class EmailChangeConfirmationRejectedException extends DomainException
{
}
