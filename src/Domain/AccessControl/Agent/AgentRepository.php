<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

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
 * Every add, lifecycle and Permission write, including direct calls and legacy revocation, acquires and validates
 * getOperationContract() before effects and holds the shared cohort fence through transaction completion.
 * Missing/incompatible composition rejects with sanitized UNAVAILABLE; never bypass through a legacy marker.
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
     * Replaces the current Agent authority atomically with its successor
     *
     * Compare the complete authoritative predecessor and require expected->canReplaceCredentialWith(replacement).
     * Return false for stale or invalid successors without any write. Never revive revoked authority, change
     * unrelated state, reuse a credential ID, or downgrade the recoverable-operation marker. Permission authority
     * changes use replacePermissionAssignments(), which must share the credential fence.
     *
     * For a recoverable predecessor, require the package-owned transaction and same-connection operation persistence.
     * Invoke AgentOperationRepository::retireCredential(expected, replacement) before persisting the successor,
     * under the shared Agent/credential, original operation/delivery and destination/authority fences. Hold all fences
     * through commit; no direct caller or repository replacement may bypass cancellation. Missing correlation or
     * unsupported participation throws a sanitized AgentOperationRejectedException and aborts the entire transaction.
     * Do not begin/commit a nested transaction, use keys/sinks/events, or apply new-work capacity limits.
     * Cancellation failure, Agent write failure and subsequent audit failure roll back both states together.
     * Consumer authority/reassignment writers and delivery claims/admission/outcomes use the same fences and epochs.
     * A known legacy predecessor may become recoverable only with a new credential/revision through explicit
     * authorized rotation. Require compatible same-connection operation persistence and the package transaction;
     * persist its new operation, separate delivery material and audit atomically with that successor. Do not retire
     * or fabricate an operation for the legacy predecessor. Legacy revocation preserves the false marker.
     * Reconstitution preserves all persisted authority through Agent::reconstitute() or equivalent validated adapter
     * hydration. Reject unknown markers and inconsistent correlation; never infer false from a missing operation.
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
     * replacement must preserve the recoverable-operation marker. Every replacement PermissionId must remain an
     * authoritative ADMIN_SAFE Permission through the enclosing Unit of
     * Work under the shared permission-reference and tier fence. Managed tier promotion must use the same fence.
     * Share the Agent credential fence with lifecycle retirement: a stale Permission write cannot restore authority.
     *
     * @throws Exception When an error occurs
     */
    public function replacePermissionAssignments(Agent $expected, Agent $replacement): bool;

    /**
     * Adds one newly provisioned Agent including its recoverable-operation marker
     *
     * Require a new stable identity; never upsert over existing/retired authority to bypass replace() and cancellation.
     *
     * @throws Exception When an error occurs
     */
    public function add(Agent $agent): void;
}
