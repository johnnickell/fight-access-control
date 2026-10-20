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
     *
     * Select consumer-owned activation copy from getActivationOrigin(), not purpose, events or routes.
     * Registration and private invitation both retain purpose activation. Other purposes carry null origin.
     * Origin grants no authority; repeated delivery identities must converge without logging credential material.
     */
    public function deliver(CredentialDeliveryInvocation $invocation): CredentialDeliveryOutcome;
}
