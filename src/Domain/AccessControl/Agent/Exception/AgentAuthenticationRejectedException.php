<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates an Agent authentication attempt that consumers must treat generically.
 */
final class AgentAuthenticationRejectedException extends DomainException
{
}
