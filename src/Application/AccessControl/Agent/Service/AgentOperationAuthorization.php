<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;

/**
 * Interface AgentOperationAuthorization
 *
 * Participates in the package transaction using authoritative consumer policy and the real authenticated invoker.
 */
interface AgentOperationAuthorization
{
    /**
     * Validates original scope and destination and returns the authenticated invoker's safe audit identity
     *
     * Check current caller/delegation and destination ownership on the shared connection before key lookup.
     * Hold authority revisions/epochs and destination binding fences through transaction completion, shared by
     * every authority writer. Do not begin or commit a transaction. Cached allows and actor strings are not authority.
     * Reject unsupported integration, expired delegation or revoked authority with AgentOperationRejectedException.
     * A delegated worker remains itself and must not rewrite the original scope. Audit identity is bounded to the
     * same 128-character identifier alphabet as a scope caller ID and must contain no credentials/provider details.
     */
    public function authorize(AgentOperationScope $scope, AgentCredentialDestination $destination): string;
}
