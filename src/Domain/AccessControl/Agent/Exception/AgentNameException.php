<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Indicates that an Agent name is not safe for operator-facing identification.
 */
final class AgentNameException extends DomainException
{
}
