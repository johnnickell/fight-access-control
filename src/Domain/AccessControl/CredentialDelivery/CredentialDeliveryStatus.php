<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

/**
 * Enum CredentialDeliveryStatus
 *
 * Identifies secret-free credential-delivery lifecycle state.
 */
enum CredentialDeliveryStatus: string
{
    case PENDING = 'pending';
    case CLAIMED = 'claimed';
    case RETRY_PENDING = 'retry_pending';
    case DELIVERED = 'delivered';
    case PERMANENT_FAILURE = 'permanent_failure';
    case EXPIRED = 'expired';
    case INVALIDATED = 'invalidated';
}
