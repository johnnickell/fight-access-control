<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that an Agent credential lifecycle transition is not authoritative.
 */
final class AgentCredentialException extends DomainException
{
}
