<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\Agent\Service;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationScope;

/**
 * Interface AgentOperationAuthorization
 *
 * Checks authoritative consumer policy and the real authenticated invoker for issuance and safe status reads.
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

    /**
     * Validates current read authority without a write transaction or side effects
     *
     * Authenticate the real invoker, then check original scope, explicit current delegation and destination binding
     * against authoritative consumer policy. A null target checks scope/destination before lookup; a non-null target
     * additionally checks authority over the original Agent before disclosure, even for retired/terminal credentials.
     * Recheck every call rather than caching the earlier allow. The handler calls again after reading, including on
     * absence. Never impersonate the originating caller, begin/commit a Unit of Work, write audit or mutate authority.
     * Reject with UNAUTHORIZED without existence details; unsupported/unavailable integrations reject UNAVAILABLE.
     * This authorizes a safe snapshot only, not delivery admission, activation, launch or any future use.
     */
    public function authorizeRead(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        ?AgentId $target
    ): void;
}
