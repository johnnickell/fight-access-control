<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent;

use Exception;
use Fight\Common\Domain\Repository\Pagination;
use Fight\Common\Domain\Repository\ResultSet;

/**
 * Persists Agent authority aggregates.
 */
interface AgentRepository
{
    /**
     * Retrieves an Agent by its stable identifier.
     *
     * @throws Exception When an error occurs
     */
    public function getById(AgentId $id): ?Agent;

    /**
     * Retrieves one page of Agent authorities.
     *
     * @throws Exception When an error occurs
     */
    public function getAll(Pagination $pagination): ResultSet;

    /**
     * Atomically replaces the current Agent authority with its successor.
     *
     * Returns false when the expected predecessor has already lost authority, the replacement changes identity, or
     * its direct Permission membership or Permission-assignment revision differs. Direct Permission authority changes
     * must use replacePermissionAssignments().
     *
     * @throws Exception When an error occurs
     */
    public function replace(Agent $expected, Agent $replacement): bool;

    /**
     * Atomically replaces direct Permission assignments while the expected predecessor remains current.
     *
     * Implementations compare all Agent state, reject changes outside Permission assignments, require direct
     * Permission membership to change, and require the assignment revision to advance by exactly one. Every
     * replacement PermissionId must remain authoritative through the enclosing Unit of Work under the shared
     * permission-reference fence.
     *
     * @throws Exception When an error occurs
     */
    public function replacePermissionAssignments(Agent $expected, Agent $replacement): bool;

    /**
     * Adds one newly provisioned Agent.
     *
     * @throws Exception When an error occurs
     */
    public function add(Agent $agent): void;
}
