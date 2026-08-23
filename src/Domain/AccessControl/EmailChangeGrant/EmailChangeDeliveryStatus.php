<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\EmailChangeGrant;

/**
 * Identifies the safe operational state of email-change delivery work.
 */
enum EmailChangeDeliveryStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case FAILED = 'failed';
    case CONFIRMED = 'confirmed';
}
