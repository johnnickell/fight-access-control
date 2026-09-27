<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Exception;
use Fight\AccessControl\Domain\AccessControl\Permission\Permission;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Interface AgentRepository
 *
 * Persists Agent authority aggregates.
 */
interface AgentRepository
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
     * Returns false when the expected predecessor has already lost authority, the replacement changes identity, or
     * its direct Permission membership or Permission-assignment revision differs. Direct Permission authority changes
     * must use replacePermissionAssignments().
     *
     * @throws Exception When an error occurs
     */
    public function replace(Agent $expected, Agent $replacement): bool;

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
     * replacement PermissionId must remain an authoritative ADMIN_SAFE Permission through the enclosing Unit of
     * Work under the shared permission-reference and tier fence. Managed tier promotion must use the same fence.
     *
     * @throws Exception When an error occurs
     */
    public function replacePermissionAssignments(Agent $expected, Agent $replacement): bool;

    /**
     * Adds one newly provisioned Agent
     *
     * @throws Exception When an error occurs
     */
    public function add(Agent $agent): void;
}
