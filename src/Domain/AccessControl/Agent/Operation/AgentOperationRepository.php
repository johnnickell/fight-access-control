<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

/**
 * Interface AgentOperationRepository
 *
 * Persists operations, prepared delivery and slot order on the package Unit of Work's shared connection.
 */
interface AgentOperationRepository
{
    /**
     * Retrieves authoritative retained correlation only after current scope and destination authorization
     *
     * Includes permanent tombstones and unknown canonical versions; never treats either as absent.
     */
    public function getByKey(AgentOperationKey $key): ?AgentCredentialOperation;

    /**
     * Creates the next write reservation under the shared destination ownership and reassignment fence
     *
     * Counters span all Agents and scopes for the stable destination ID, never reset on rebinding, and roll back
     * with failed issuance. Authorization already holds the binding fence through transaction completion.
     */
    public function reserveDestinationWrite(AgentCredentialDestination $destination): int;

    /**
     * Adds one operation with its separately encrypted delivery copy under atomic uniqueness and capacity fences
     *
     * Enforces scoped-key uniqueness, global delivery-ID uniqueness and limits over authoritative pending counts.
     * Raise AgentOperationCollisionException only for scoped-key collision; all loser writes must roll back before
     * resolution. Never evict retained correlation. Every writer shares admission, authority and slot fences.
     * Downstream expected-state lifecycle writes must retire material atomically with Agent credential retirement.
     */
    public function add(AgentCredentialOperation $operation, AgentOperationLimits $limits): void;
}
