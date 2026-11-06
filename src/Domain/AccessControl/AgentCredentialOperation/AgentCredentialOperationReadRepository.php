<?php

declare(strict_types=1);

namespace Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation;

use DateTimeImmutable;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentDeliveryKeyVersion;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenancePolicy;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Maintenance\AgentMaintenanceWork;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentDeliveryWorkView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;

/**
 * Interface AgentCredentialOperationReadRepository
 *
 * Reads authoritative secret-free metadata without exposing material-bearing roots, claims or writes.
 * A consumer persistence implementation may implement both repository contracts on the same connection;
 * neither contract owns the Unit of Work. Outside a transaction the cohort snapshot is observation only.
 */
interface AgentCredentialOperationReadRepository extends AgentOperationContractRepository
{
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
     * Project AgentDeliveryWorkView selection evidence and its AgentOperationView from the SAME snapshot;
     * include due time, retained-copy presence, pinned retention, cleanup acknowledgement and the authoritative
     * current stable-slot reservation. Never load material, tokens or receipts, take claims, commit or mutate.
     * No query-time lifecycle transitions.
     * The handler rechecks current scope/delegation and each target before any disclosure. Consumer indexes must
     * support bounded selection, not an unbounded in-process scan. Storage failure throws, never returns empty work.
     *
     * @return list<AgentDeliveryWorkView>
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
     * Do not load ciphertext or attempts. Return AgentDeliveryWorkView selection evidence and original status
     * from the SAME snapshot, including retained-copy presence, pinned retention and sink cleanup acknowledgement.
     * The current reservation may be null: obsolete reservations remain maintenance work.
     * No mutation, claims, capacity check or commit.
     * Use indexed bounded selection. Continue with the last ID even when a maintenance attempt defers or is unchanged;
     * restart at null after the final page to catch concurrent earlier inserts. A page is never a complete key count.
     * Storage failure throws; do not return an empty page on failure.
     *
     * @return list<AgentDeliveryWorkView>
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
}
