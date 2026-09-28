<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Security;

/**
 * Enum AgentDeliveryResult
 *
 * Reports delivery coordination only, never activation, launch permission or secret material.
 */
enum AgentDeliveryResult: string
{
    case DELIVERED = 'delivered';
    case RETIRED = 'retired';
    case EXPIRED = 'expired';
    case TERMINAL = 'terminal';
    case RETRYABLE = 'retryable';
    case DEFERRED = 'deferred';
    case REJECTED = 'rejected';
    case UNAVAILABLE = 'unavailable';
    case INDETERMINATE = 'indeterminate';
    case RECONCILIATION_REQUIRED = 'reconciliation_required';
}
