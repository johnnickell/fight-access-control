<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\CredentialDelivery;

/**
 * Enum CredentialDeliveryFailure
 *
 * Classifies provider outcomes without retaining arbitrary provider details.
 */
enum CredentialDeliveryFailure: string
{
    case RETRYABLE_PROVIDER = 'retryable_provider';
    case UNEXPECTED_PROVIDER = 'unexpected_provider';
    case PERMANENT_PROVIDER = 'permanent_provider';
}
