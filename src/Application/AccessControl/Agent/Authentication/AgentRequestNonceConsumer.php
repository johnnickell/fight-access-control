<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Authentication;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use SensitiveParameter;

/**
 * Interface AgentRequestNonceConsumer
 *
 * Atomically confirms current Agent authority while consuming one globally unique request nonce.
 */
interface AgentRequestNonceConsumer
{
    /**
     * Uses the nonce exactly once after confirming current Agent authority
     *
     * The consumer composes its authority check and nonce write in one atomic operation. It returns false when the
     * nonce is already consumed or the Agent authority is no longer current. Throw on operational failure, never
     * return false as a storage fallback. Participate in the enclosing package transaction without beginning or
     * committing another transaction. A confirmed consume remains consumed if later principal resolution fails;
     * no caller may restore it or reuse this port for continuation admission.
     */
    public function consume(
        AgentId $agentId,
        AgentCredentialId $credentialId,
        int $credentialRevision,
        int $permissionAssignmentRevision,
        #[SensitiveParameter] string $nonce,
        DateTimeImmutable $expiresAt
    ): bool;
}
