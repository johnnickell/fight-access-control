<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

use Fight\Common\Domain\Exception\DomainException;

/**
 * Class AgentUpdateException
 *
 * Indicates that the current Agent cannot accept a name update.
 */
final class AgentUpdateException extends DomainException
{
}
