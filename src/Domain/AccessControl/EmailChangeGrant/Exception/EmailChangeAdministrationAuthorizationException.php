<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports unauthorized administration of another user's email change.
 */
final class EmailChangeAdministrationAuthorizationException extends DomainException
{
}
