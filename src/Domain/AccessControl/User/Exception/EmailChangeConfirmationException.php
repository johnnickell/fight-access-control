<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\User\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports an invalid aggregate email-change confirmation.
 */
final class EmailChangeConfirmationException extends DomainException
{
}
