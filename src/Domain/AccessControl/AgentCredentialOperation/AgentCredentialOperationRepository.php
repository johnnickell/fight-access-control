<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use SensitiveParameter;

/**
 * Interface AgentCredentialOperationRepository
 *
 * Persists operations, prepared delivery and slot order on the package Unit of Work's shared connection.
 * Secret-free queries use the independent AgentCredentialOperationReadRepository, not this write contract.
 * Every writer, including direct calls, acquires and validates getOperationContract() before effects, holding
 * the cohort fence through commit alongside subordinate authority/Agent/slot/key fences. Unsupported or missing
 * composition rejects sanitized UNAVAILABLE, including reservation, retirement and material-free outcomes.
 */
interface AgentCredentialOperationRepository extends AgentOperationContractRepository
{
    /**
     * Retrieves authoritative retained correlation only after current scope and destination authorization
     *
     * Includes permanent tombstones and unknown canonical versions; never treats either as absent.
     * Return the exact scoped key and coherent original request/issuance, or null for authoritative absence only.
     * Inconsistent correlation is a repository fault; failed reads must not return null or a different key.
     */
    public function getByKey(AgentOperationKey $key): ?AgentCredentialOperation;

    /**
     * Replaces one exact maintenance snapshot without requiring live delivery authority over an obsolete slot
     *
     * Require package transaction plus current maintenance authorization on the same connection. Compare original
     * key, canonical version/request, full issuance, authoritative state revision and every expected persisted field;
     * replacement advances revision exactly once and retains original correlation. Only validated rewrap, expiry,
     * permanent source-loss/corruption or cleanup-ack successors are permitted. Share operation, authority, lifecycle,
     * destination-history and old/new key-reference fences with ALL writers. Reject stale snapshots, missing records,
     * resurrection and target versions closed to writes. Update key references atomically with the copy; never count
     * only a work page or discard a tombstone to meet capacity. No sink/key destruction, nested transaction or capacity
     * check. Sanitize failures and annotate concrete sensitive arguments/callbacks. Unsupported participation rejects.
     */
    public function replaceMaintenance(
        #[SensitiveParameter] AgentCredentialOperation $expected,
        #[SensitiveParameter] AgentCredentialOperation $replacement
    ): void;

    /**
     * Creates the next write reservation under the shared destination ownership and reassignment fence
     *
     * Counters span all Agents and scopes for the stable destination ID, never reset on rebinding, and roll back
     * with failed issuance. Authorization already holds the binding fence through transaction completion.
     * Return a positive version; invalid reservations are repository faults, not invalid caller requests.
     */
    public function reserveDestinationWrite(AgentCredentialDestination $destination): int;

    /**
     * Removes the predecessor's delivery copy as part of AgentRepository::replace on the same connection
     *
     * Require an active package transaction with the authoritative Agent predecessor held current. Resolve exactly
     * one original operation by Agent/credential ID/revision, including already delivered or material-free records;
     * missing, ambiguous or inconsistent correlation fails closed. Lock its immutable original destination binding,
     * delivery and state revision against claims, admission, acknowledgement, cleanup and all authority writers.
     * Persist operation->retireCredential(expected, replacement) by exact expected state, including revision, while
     * invalidating pending, claimed and admitted work. Any failure aborts the enclosing Agent write and audit.
     *
     * Claims/admissions store this operation revision and current consumer authority epochs; every completion checks
     * both plus current credential/destination and deadline. Revoke/regrant must advance epochs, never restore an old
     * allow. New claims cannot revive retired work. Late external effects may stage inert bytes but cannot acknowledge.
     * Preserve key/canonical request, original issuance, slot order and deduplication evidence permanently. Remove the
     * delivery copy immediately, regardless of retention or capacity; do not read keys, decrypt or call sinks.
     * Consumer policy protects caller entry points; actor strings are audit only. No extra routine human approval.
     * Throw sanitized AgentOperationRejectedException on unsupported participation, storage faults or conflict;
     * omit provider diagnostics, material and previous exceptions. Implementations must mark sensitive inputs.
     */
    public function retireCredential(
        #[SensitiveParameter] Agent $expected,
        #[SensitiveParameter] Agent $replacement
    ): void;

    /**
     * Replaces one exact delivery snapshot under shared Agent, authority, destination and operation fences
     *
     * Require the active package transaction and compare authoritative stored operation revision and immutable tuple
     * with expected, and the current Agent identity/credential/state with expectedAgent. Hold locks through commit,
     * shared with AgentRepository::replace, all delivery writers, authority mutation, cleanup and slot reassignment.
     * Require replacement's identical original binding and revision exactly one higher. Writes persist attempt token,
     * monotonic fence, policy, lease, admission epochs/deadline, retry time, verified receipt, closed failure class
     * and disposition atomically with material.
     * Never recreate a missing operation or revive retired authority, even through direct non-HTTP calls. No capacity
     * check, decryption, sink call or independent transaction. Throw sanitized failures without previous exceptions;
     * concrete implementations must annotate Agent inputs and Unit of Work callbacks to redact failure traces.
     */
    public function replaceDelivery(
        AgentCredentialOperation $expected,
        AgentCredentialOperation $replacement,
        #[SensitiveParameter] Agent $expectedAgent
    ): void;

    /**
     * Adds one operation with its separately encrypted delivery copy under atomic uniqueness and capacity fences
     *
     * Enforces scoped-key uniqueness, global delivery-ID uniqueness and limits over authoritative pending counts.
     * Raise AgentOperationCollisionException only for scoped-key collision; all loser writes must roll back before
     * resolution. Never evict retained correlation. Every writer shares admission, authority and slot fences.
     * Expected-state lifecycle writes retire material atomically with Agent credential retirement. Add and every
     * material writer hold the shared key-version write/reference fence through commit and reject NEW references
     * into closed versions. Existing references may drain; physical retirement must not race new references or key use.
     * Never delete scoped correlation tombstones.
     */
    public function add(AgentCredentialOperation $operation, AgentOperationLimits $limits): void;
}
