<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class EmailChangeConfirmationRejectedException
 *
 * Reports a generically rejected email-change confirmation.
 */
final class EmailChangeConfirmationRejectedException extends DomainException
{
}
