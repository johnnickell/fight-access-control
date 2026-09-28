<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

/**
 * Enum AgentMaintenanceResult
 */
enum AgentMaintenanceResult: string
{
    case REWRAPPED = 'rewrapped';
    case EXPIRED = 'expired';
    case TERMINAL = 'terminal';
    case CLEANED = 'cleaned';
    case UNCHANGED = 'unchanged';
    case RETRYABLE = 'retryable';
    case REJECTED = 'rejected';
    case UNAVAILABLE = 'unavailable';
    case INDETERMINATE = 'indeterminate';
}
