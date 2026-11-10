<?php

declare(strict_types=1);

namespace Fight\AccessControl\Application\AccessControl\AgentCredentialOperation;

use Fight\AccessControl\Domain\AccessControl\Agent\AgentId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentCredentialDestination;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationScope;
use Fight\AccessControl\Domain\AccessControl\Audit\AnonymousId;
use Fight\AccessControl\Domain\AccessControl\Audit\SystemId;
use Fight\AccessControl\Domain\AccessControl\User\UserId;

/**
 * Interface AgentOperationAuthorization
 *
 * Checks authoritative consumer policy and the real authenticated invoker for issuance and safe status reads.
 */
interface AgentOperationAuthorization
{
    /**
     * Validates original scope and destination and returns explicit typed originating audit provenance
     *
     * Check current caller/delegation and destination ownership on the shared connection before key lookup.
     * Hold authority revisions/epochs and destination binding fences through transaction completion, shared by
     * every authority writer. Do not begin or commit a transaction. Cached allows and actor strings are not authority.
     * Reject expired delegation or revoked authority with AgentOperationRejectedException(UNAUTHORIZED), and
     * explicitly unsupported/unavailable integration with UNAVAILABLE. Other reasons violate this port contract;
     * arbitrary provider/programmer faults remain internal, never ordinary permission refusal.
     * Authenticate the real invoker independently of audit provenance. A delegated worker remains itself and must
     * not rewrite the original scope or substitute its own identity for the admitted originating User or Agent.
     * Return explicitly established originating provenance, not a parsed caller label or missing-data fallback.
     * Deliberate autonomous or anonymous entry may establish the corresponding fixed identity. Supplying an audit
     * identity never grants authority, and neither operation nor caller labels are necessarily UUIDs. Null explicitly
     * leaves audit provenance absent: authorized original-key resolution needs no new audit-only identity, while an
     * enabled new mutation must reject absent provenance before its first state change. Null never grants authority.
     */
    public function authorize(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination
    ): UserId|AgentId|SystemId|AnonymousId|null;

    /**
     * Validates current rotation authority before key lookup and returns typed originating audit provenance
     *
     * Apply authorize's scope, real invoker, explicit delegation, destination and shared-connection requirements,
     * additionally fencing current authority over the target through commit. Recheck on every original-key retry,
     * including retired credentials; authorization must not require the original predecessor to remain current.
     * All target-policy writers share these fences. Do not reveal key existence on denial or trust caller booleans.
     */
    public function authorizeRotation(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        AgentId $target
    ): UserId|AgentId|SystemId|AnonymousId|null;

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
