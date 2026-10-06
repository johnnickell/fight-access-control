<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use DateTimeImmutable;
use Exception;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationContractRepository;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;
use SensitiveParameter;

/**
 * Interface AgentRepository
 *
 * Persists Agent authority aggregates.
 * Every add, name, lifecycle and Permission write, including direct calls, validates getOperationContract()
 * before effects and holds its shared cohort fence through transaction completion.
 * Missing/incompatible composition rejects with sanitized UNAVAILABLE.
 */
interface AgentRepository extends AgentOperationContractRepository
{
    /**
     * Retrieves an Agent by its stable identifier
     *
     * @throws Exception When an error occurs
     */
    public function getById(AgentId $id): ?Agent;

    /**
     * Retrieves an Agent by its current public credential identifier
     *
     * @throws Exception When an error occurs
     */
    public function getByCredentialId(AgentCredentialId $credentialId): ?Agent;

    /**
     * Retrieves one page of Agent authorities
     *
     * @return ResultSet<Agent>
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /**
     * Returns whether an authoritative Agent directly holds the Permission
     *
     * This read is advisory for preview; managed promotion must recheck under the shared write fence.
     */
    public function hasPermissionAssignment(PermissionId $permissionId): bool;

    /**
     * Renames the authoritative active Agent under the transaction-duration credential and cohort fences
     *
     * Require the package transaction on the shared connection, compatible cohort admission and exact current
     * operation correlation, including no-ops and direct calls. Hold the same Agent fence as lifecycle and Permission
     * writers through completion. Read the current aggregate under that fence and apply Agent::rename(name, renamedAt).
     * Return true only for a real persisted name change; return false only for an unchanged normalized name on a
     * still ACTIVE target. Missing/inactive targets throw AgentUpdateException; missing/inconsistent correlation or
     * unsupported transaction participation throws sanitized AgentOperationRejectedException. Never return false
     * for missing, inactive or unavailable authority. Storage failures throw and abort the enclosing transaction.
     *
     * Accept only name intent, never a caller's whole-Agent successor or expected old name. Last committed name wins;
     * never overwrite current credentials, Permissions, revisions, creation time or operation/delivery state. Change
     * only name and updatedAt, reject backdated real updates, and make no write for no-ops. Preserve original issuance
     * and delivery correlation without retirement, new issuance or audit fabrication. Roll back the name with the
     * enclosing Unit of Work. Do not commit independently, publish events, invoke keys/sinks or apply new-work quotas.
     * Other whole-Agent writers must compare complete predecessors so stale lifecycle/Permission writes cannot undo
     * a rename; consumers retry conflicting authorized intents from current state. Provenance grants no authority.
     *
     * @throws Exception When an error occurs
     */
    public function rename(AgentId $id, AgentName $name, DateTimeImmutable $renamedAt): bool;

    /**
     * Replaces the current Agent authority atomically with its successor
     *
     * Compare the complete authoritative predecessor and require expected->canReplaceCredentialWith(replacement).
     * Return false for stale or invalid successors without any write. Never revive revoked authority, change
     * unrelated state or reuse a credential ID. Permission authority
     * changes use replacePermissionAssignments(), which must share the credential fence.
     *
     * Require the package-owned transaction and same-connection operation persistence for every predecessor.
     * Invoke AgentOperationRepository::retireCredential(expected, replacement) before persisting the successor,
     * under the shared Agent/credential, original operation/delivery and destination/authority fences. Hold all fences
     * through commit; no direct caller or repository replacement may bypass cancellation. Missing correlation or
     * unsupported participation throws a sanitized AgentOperationRejectedException and aborts the entire transaction.
     * Do not begin/commit a nested transaction, use keys/sinks/events, or apply new-work capacity limits.
     * Cancellation failure, Agent write failure and subsequent audit failure roll back both states together.
     * Consumer authority/reassignment writers and delivery claims/admission/outcomes use the same fences and epochs.
     * Rotation persists its new operation, separate delivery material and audit atomically with the successor.
     * Reconstitution preserves all persisted authority through Agent::reconstitute() or equivalent validated adapter
     * hydration. Reject missing or inconsistent current-operation correlation; never fabricate issuance.
     * Mark both Agent parameters sensitive in every implementation and forwarding method: interface attributes
     * are not inherited. Cancellation failures must not expose authentication envelopes through outer trace frames.
     *
     * @throws Exception When an error occurs
     */
    public function replace(
        #[SensitiveParameter] Agent $expected,
        #[SensitiveParameter] Agent $replacement
    ): bool;

    /**
     * Validates exact ADMIN_SAFE Permission definitions under the transaction-duration reference and tier fence
     *
     * The supplied definitions are expected snapshots, not authority. Implementations compare their identity with
     * current definitions and hold the fence through Unit of Work completion, including no-op assignments. Share
     * this fence with managed tier changes and replacePermissionAssignments().
     *
     * @phpstan-param list<Permission> $expectedPermissions
     *
     * @throws Exception When an error occurs
     */
    public function validatePermissionAssignments(array $expectedPermissions): bool;

    /**
     * Replaces direct Permission assignments atomically while the expected predecessor remains current
     *
     * Implementations compare all Agent state, reject changes outside Permission assignments, require direct
     * Permission membership to change, and require the assignment revision to advance by exactly one. Every
     * replacement must preserve current-operation correlation. Every replacement PermissionId must remain an
     * authoritative ADMIN_SAFE Permission through the enclosing Unit of
     * Work under the shared permission-reference and tier fence. Managed tier promotion must use the same fence.
     * Share the Agent credential fence with lifecycle retirement: a stale Permission write cannot restore authority.
     *
     * @throws Exception When an error occurs
     */
    public function replacePermissionAssignments(Agent $expected, Agent $replacement): bool;

    /**
     * Adds one newly provisioned Agent atomically with its credential operation
     *
     * Require a new stable identity; never upsert over existing/retired authority to bypass replace() and cancellation.
     * The enclosing transaction must persist matching issuance, delivery material and audit before commit.
     *
     * @throws Exception When an error occurs
     */
    public function add(Agent $agent): void;
}
