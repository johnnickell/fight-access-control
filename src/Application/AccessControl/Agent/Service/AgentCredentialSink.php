<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentCredentialInvocation;
use Fight\AccessControl\Domain\AccessControl\Agent\Delivery\AgentDeliveryReceipt;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;
use SensitiveParameter;

/**
 * Interface AgentCredentialSink
 *
 * Stages immutable protected credentials; a receipt is neither selection, enrollment activation nor use authority.
 */
interface AgentCredentialSink
{
    /**
     * Validates support for immutable staging, exact receipt verification and retained ordering before decryption
     *
     * Resolve only server-registered slots. Reject unsupported capability with UNSUPPORTED_SINK. No arbitrary path,
     * URL, generic secret-store put or latest-arrival selection satisfies this contract. Never start a transaction.
     */
    public function assertSupported(AgentIssuance $issuance): void;

    /**
     * Stages original bytes outside all database transactions using the global delivery ID alone for idempotency
     *
     * Atomically bind ID to original bytes and every issuance field; changed bytes/tuple reject without writing.
     * Retain deduplication and per-slot nondecreasing write high-water evidence even after secret cleanup/rebinding.
     * Equal order with a different binding rejects. Identical retry returns its original receipt without changing
     * selection. Lower-order delayed writes cannot overwrite/select/reactivate newer material. Entries remain inert
     * until consumer selection independently validates the exact current package/destination tuple, not a pointer.
     * Throw typed sanitized AgentDeliveryFailedException; concrete implementations must annotate sensitive inputs.
     */
    public function stage(#[SensitiveParameter] AgentCredentialInvocation $invocation): AgentDeliveryReceipt;

    /**
     * Verifies a durable receipt against the exact full original tuple without reading secret material
     *
     * Return false for missing, swapped, unverifiable or lost entries. A format-valid ID alone proves nothing.
     * Never return a digest, credential, key path or secret-read capability. Run outside all package transactions.
     */
    public function verify(AgentDeliveryReceipt $receipt, AgentIssuance $issuance): bool;
}
