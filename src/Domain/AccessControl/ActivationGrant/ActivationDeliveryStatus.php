<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\ActivationGrant;

/**
 * Identifies the safe operational state of activation delivery work.
 */
enum ActivationDeliveryStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case FAILED = 'failed';
    case CONFIRMED = 'confirmed';
    case EXPIRED = 'expired';
}
