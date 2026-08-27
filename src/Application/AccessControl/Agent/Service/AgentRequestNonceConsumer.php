<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentCredentialId;
use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;

/**
 * Atomically confirms current Agent authority while consuming one globally unique request nonce.
 */
interface AgentRequestNonceConsumer
{
    /**
     * Consumes the nonce exactly once through the supplied expiry after confirming that the Agent remains active and
     * its current credential ID, credential revision, and Permission-assignment revision match the supplied authority.
     *
     * The consumer composes its authority check and nonce write in one atomic operation. It returns false when the
     * nonce is already consumed or the Agent authority is no longer current.
     */
    public function consume(
        AgentId $agentId,
        AgentCredentialId $credentialId,
        int $credentialRevision,
        int $permissionAssignmentRevision,
        string $nonce,
        DateTimeImmutable $expiresAt
    ): bool;
}
