<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\Agent\Operation;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\Agent\Agent;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\Agent\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\Agent\Query\AgentOperationView;
use SensitiveParameter;

/**
 * Interface AgentOperationRepository
 *
 * Persists operations, prepared delivery and slot order on the package Unit of Work's shared connection.
 * Every writer, including direct calls, acquires and validates getOperationContract() before effects, holding
 * the cohort fence through commit alongside subordinate authority/Agent/slot/key fences. Unsupported or missing
 * composition rejects sanitized UNAVAILABLE, including reservation, retirement and material-free outcomes.
 */
interface AgentOperationRepository extends AgentOperationContractRepository
{
    /**
     * Retrieves authoritative retained correlation only after current scope and destination authorization
     *
     * Includes permanent tombstones and unknown canonical versions; never treats either as absent.
     */
    public function getByKey(AgentOperationKey $key): ?AgentCredentialOperation;

    /**
     * Retrieves one coherent authoritative secret-free snapshot after scope and destination read authorization
     *
     * Project original issuance, persisted canonical version and current recorded delivery/credential disposition
     * together, including tombstones and unknown versions. Do not load material, decrypt, take a claim, apply admission
     * limits or mutate state. Never read from an eventually consistent replica. No package write transaction is needed.
     * Return null only for authoritative absence, never storage failure; throw on unavailable/inconsistent storage.
     * Absence cannot establish rollback. The handler rechecks current authority including the target before disclosure.
     */
    public function getStatusByKey(AgentOperationKey $key): ?AgentOperationView;

    /**
     * Retrieves a bounded authoritative secret-free due-work projection after discovery authorization
     *
     * Select only this original scope and exact destination binding, with unfinished current-credential delivery
     * material and getDeliveryDueAt() <= now: pending, due retries and abandoned expired claims. Before ordering
     * or limiting, require the issuance's reserved write version to equal the authoritative current reservation
     * for the stable destination ID across ALL scopes, Agents and binding revisions. Use the durable reservation
     * counter maintained by reserveDestinationWrite(), not the maximum among due/matching operations or sink arrivals.
     * Obsolete writes cannot occupy a batch even if the newer reservation is not due, completed or in another scope.
     * Order eligible work ascending by due time then global delivery ID bytewise, and apply limit (1..100).
     * Retention-expired eligible work stays due for terminalization; delivered/retired/terminal work never reenters
     * selection. Exclusion does not retire material or change status; maintenance owns obsolete-copy cleanup.
     * Selection grants no delivery authority: claim/admission/completion must still check current slot reservation.
     * Include persisted canonical versions so unsupported versions fail closed rather than appear absent. Use
     * authoritative storage, never replicas, issuance events, process memory or new-work capacity.
     * Project AgentOperationView only; never load material, tokens or receipts, take claims, commit or mutate.
     * No query-time lifecycle transitions.
     * The handler rechecks current scope/delegation and each target before any disclosure. Consumer indexes must
     * support bounded selection, not an unbounded in-process scan. Storage failure throws, never returns empty work.
     *
     * @return list<AgentOperationView>
     */
    public function listDueDeliveries(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        DateTimeImmutable $now,
        int $limit
    ): array;

    /**
     * Retrieves one secret-free keyset page of retained material or eligible terminal sink cleanup
     *
     * Authoritative original scope and exact historical destination only, including obsolete slot reservations.
     * MATERIAL includes EVERY retained copy regardless of disposition/version/key;
     * CLEANUP uses canCleanup(now, policy).
     * Sort by global delivery ID bytewise ascending, exclusively after the optional cursor, before applying batch size.
     * Do not load ciphertext or attempts. Return original status views; no mutation, claims, capacity check or commit.
     * Use indexed bounded selection. Continue with the last ID even when a maintenance attempt defers or is unchanged;
     * restart at null after the final page to catch concurrent earlier inserts. A page is never a complete key count.
     * Storage failure throws; do not return an empty page on failure.
     *
     * @return list<AgentOperationView>
     */
    public function listMaintenance(
        AgentOperationScope $scope,
        AgentCredentialDestination $destination,
        AgentMaintenanceWork $work,
        DateTimeImmutable $now,
        AgentMaintenancePolicy $policy,
        ?AgentDeliveryId $after
    ): array;

    /**
     * Returns the authoritative global reference count for one delivery wrapping version without reading material
     *
     * Count EVERY retained delivery copy across all scopes, states, versions, pending/claimed/obsolete records and
     * batches. Storage faults throw; zero is only an observation, never permission to retire a key. No commit/mutation.
     * Consumer physical retirement must first close this version's write admission under the shared key fence,
     * recount atomically under that fence, and wait for all leased/in-flight key uses and independent authentication
     * envelope dependencies. All add/replace/delivery/lifecycle/maintenance writers participate in that fence,
     * including rollback and restore. Rewrap/add into closed versions rejects. Existing references may still drain;
     * no new reference is admitted. Counts may not silently omit unsupported records.
     */
    public function countDeliveryKeyReferences(AgentDeliveryKeyVersion $version): int;

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
