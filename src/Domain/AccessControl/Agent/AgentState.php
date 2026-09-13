<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

/**
 * Enum AgentState
 *
 * Represents the lifecycle state of an Agent identity.
 */
enum AgentState: string
{
    case PROVISIONED = 'provisioned';
    case ACTIVE = 'active';
    case REVOKED = 'revoked';
}
