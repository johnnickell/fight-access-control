<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Authorization\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that a current security context cannot select one authenticated authority safely.
 */
final class CurrentSecurityContextException extends DomainException
{
}
