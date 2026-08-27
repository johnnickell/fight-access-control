<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

/**
 * Classifies a failed Agent-principal resolution without exposing authentication material.
 */
enum AgentAuthenticationDiagnosticClassification: string
{
    case AGENT_AUTHORITY_NOT_CURRENT = 'agent_authority_not_current';
    case AUTHENTICATION_REJECTED = 'authentication_rejected';
    case PERMISSION_SNAPSHOT_INVALID = 'permission_snapshot_invalid';
    case RESOLUTION_FAILED = 'resolution_failed';
}
