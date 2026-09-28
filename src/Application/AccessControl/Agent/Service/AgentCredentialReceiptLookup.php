<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Interface AgentCredentialReceiptLookup
 *
 * Provides optional receipt-first recovery on a protected sink without retrieving credential material.
 */
interface AgentCredentialReceiptLookup
{
    /**
     * Retrieves a durable receipt for the exact original tuple outside every package transaction
     *
     * Return null only when no receipt is recorded; unavailable storage throws a sanitized delivery failure.
     * A returned receipt must still pass AgentCredentialSink::verify. Never read or return bytes, digests,
     * key paths or secret-read capabilities. Lookup does not stage, select, activate or recreate lost material.
     * Implement on the injected AgentCredentialSink when supported; absence permits at-least-once staging only
     * after a fresh confirmed admission, never from a recovered or uncertain admission.
     */
    public function lookupReceipt(AgentIssuance $issuance): ?AgentDeliveryReceipt;
}
