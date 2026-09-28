<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentIssuance;

/**
 * Interface AgentCredentialCleanup
 *
 * Removes only terminal inert bytes while permanently retaining exact replay and ordering defenses.
 */
interface AgentCredentialCleanup
{
    /**
     * Removes the exact original entry and confirms its durable non-recreation tombstone
     *
     * Run outside all database transactions after confirmed maintenance admission. Authenticate the fixed registered
     * destination; never accept arbitrary paths. Atomically bind the tombstone to delivery ID and every issuance
     * field even if stage has not arrived. Concurrent/delayed stage must reject after cleanup, including identical
     * retries. Preserve receipt/deduplication evidence and nondecreasing slot high-water across rebinding; do not
     * delete the slot or a successor. Changed tuple rejects. Repeat of the same cleanup is idempotent. Tombstoning
     * an unseen lower-order entry must not lower order or select it. A receipt is never activation/use authority.
     * Return only after durable erasure/tombstone confirmation. Lost response retries this same tuple. Unsupported
     * capability throws AgentDeliveryFailedException(UNSUPPORTED_SINK) before effects; transient outages use
     * TEMPORARY. Never return secrets, digests, paths or provider errors. Physical effect is not database-atomic.
     */
    public function remove(AgentIssuance $issuance): void;
}
