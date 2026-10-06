<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Delivery;

/**
 * Enum AgentDeliveryFailure
 */
enum AgentDeliveryFailure: string
{
    case TEMPORARY = 'temporary';
    case CORRUPT_MATERIAL = 'corrupt_material';
    case KEY_RETIRED = 'key_retired';
    case UNSUPPORTED_SINK = 'unsupported_sink';
    case INVALID_RECEIPT = 'invalid_receipt';
}
