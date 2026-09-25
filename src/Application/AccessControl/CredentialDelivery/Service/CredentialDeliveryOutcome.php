<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

/**
 * Enum CredentialDeliveryOutcome
 *
 * Classifies provider behavior without retaining provider diagnostics.
 */
enum CredentialDeliveryOutcome
{
    case DELIVERED;
    case RETRYABLE_FAILURE;
    case PERMANENT_FAILURE;
}
