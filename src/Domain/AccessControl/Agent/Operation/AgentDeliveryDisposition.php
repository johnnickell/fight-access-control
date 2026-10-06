<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

/**
 * Enum AgentDeliveryDisposition
 *
 * Describes recorded delivery independently of original issuance and current credential authority.
 */
enum AgentDeliveryDisposition: string
{
    case PENDING = 'pending';
    case DELIVERED = 'delivered';
    case RETIRED = 'retired';
    case EXPIRED = 'expired';
    case RETRYABLE = 'retryable';
    case TERMINAL = 'terminal';
}
