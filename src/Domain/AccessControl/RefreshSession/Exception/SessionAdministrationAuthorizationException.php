<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\RefreshSession\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Raised when an actor cannot administer another user's refresh sessions.
 */
final class SessionAdministrationAuthorizationException extends DomainException
{
}
