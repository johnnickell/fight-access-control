<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance;

/**
 * Enum AgentMaintenanceWork
 */
enum AgentMaintenanceWork: string
{
    case MATERIAL = 'material';
    case CLEANUP = 'cleanup';
}
