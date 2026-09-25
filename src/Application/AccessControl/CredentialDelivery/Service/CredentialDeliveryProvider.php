<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\CredentialDelivery\Service;

/**
 * Interface CredentialDeliveryProvider
 *
 * Invokes a consumer-owned provider without selecting its transport or vendor.
 */
interface CredentialDeliveryProvider
{
    /**
     * Invokes one claimed credential effect with stable idempotency identity
     */
    public function deliver(CredentialDeliveryInvocation $invocation): CredentialDeliveryOutcome;
}
