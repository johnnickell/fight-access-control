<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates an invalid authenticated Agent-principal snapshot.
 */
final class AuthenticatedAgentPrincipalException extends DomainException
{
}
