<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

/**
 * Class AgentNameUpdateConflictException
 *
 * Indicates that a name update would predate the current Agent state
 */
final class AgentNameUpdateConflictException extends AgentUpdateException
{
}
