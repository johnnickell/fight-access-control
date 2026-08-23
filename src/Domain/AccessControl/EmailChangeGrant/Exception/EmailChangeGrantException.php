<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Reports invalid email-change authority or delivery state.
 */
final class EmailChangeGrantException extends DomainException
{
}
