<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Exception;

/**
 * Class AgentUnavailableException
 *
 * Indicates that an absent or inactive Agent cannot accept a name update
 */
final class AgentUnavailableException extends AgentUpdateException
{
}
