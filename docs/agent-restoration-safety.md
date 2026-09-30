# Agent credential-state restoration safety (unreleased v0.5.0)

[TASK-00058](../planning/tasks/00058-TASK.md) adds restoration readiness to the existing
[cohort contract](agent-operation-cohorts.md), plus consumer-bindable M4 scenarios. This is the single
[current contract](agent-current-contract.md), not an old-format reader, migration engine, restore Command,
automatic rollback detector or deployment authorization. No production adapter, database, ledger provider or lock
implementation is selected. Real consumer restoration remains unqualified by the package reference tests.

## Admission evidence and ownership

`AgentOperationContract` now requires a sixth constructor argument, `?int $reconciledGeneration`, with **no default**.
The first five arguments retain their meaning. The value comes from the consumer's trusted cohort-admission boundary
**outside the dataset being restored**; it must equal the positive stored cohort generation. Null (missing, unverified,
unknown or unavailable evidence), a stale witness, or a restored generation mismatch yields sanitized
`AgentOperationFailure::UNAVAILABLE`. It is not a new permission, public result field or caller input.

The integer is a projection of verified evidence, **not the evidence itself**. A conforming
`AgentOperationContractRepository::getOperationContract()` must establish all of the following before supplying it:

1. The independently controlled admission boundary identifies the exact currently mounted **storage incarnation**
   and authoritative cohort. Its generation is positive, never reused, and monotonic across restore/switch-back.
   A witness for a different restored copy, namespace, database instance or cohort cannot authorize this connection.
   Identity verification happens in the adapter/trusted boundary; equality of two integers is not that verification.
2. The boundary has verified a lossless current-contract reconciliation checkpoint covering scoped keys and canonical
   requests, original issuance/audit facts, current Agent authority, claims/receipts, permanent terminal/tombstone
   evidence, destination ownership/order, key admission and authority epochs. The evidence identifies its complete
   scope and provenance. Its absence is unknown, not an empty initial installation.
3. Reconciliation has compared independent authoritative history with the actual active dataset and current sink
   history under quiescence. Exact receipt/delivery/credential/destination bindings agree and high-water/deduplication
   evidence never decreases. Local missing receipts, absent events, old deadlines and restored epochs prove nothing
   about external effects. Historical delivery may remain pending after external acceptance, but only with the exact
   retained original operation and verified external correlation; fresh authorized receipt recovery completes it.
4. Both repositories and **every** relevant writer share this admission fence for the package transaction's lifetime.
   A restore, generation switch or reconciliation takes the conflicting fence. Reads outside transactions freshly
   check the same boundary, with no writes/commit and no cache granting subsequent admission.

Bind the trusted witness to the live incarnation, not a restorable `ready=true` row. The witness cannot be copied
from the local generation or restored alongside the database. A consumer may reuse its existing storage/admission
control plane; no separate package authority service is introduced. Failure of that boundary denies operations,
not a fallback to a local flag. Runtime adapter faults throw sanitized UNAVAILABLE without provider details or a
previous throwable. The package validates the projected contract but cannot authenticate an adapter's claims.

**Supported restore is necessarily controlled.** Before replacing data, the consumer must close admission at a
boundary that all connections/writers obey. Undetected out-of-band rollback, a bypassing binary, or an independently
restored witness is unsupported. No package-only check can detect arbitrary physical rollback or recall disclosed
bytes. Initial adoption qualifies a fresh incarnation with affirmative inventory of existing external effects; it
must not bootstrap readiness simply because local operation storage is empty.

Once admitted, ordinary writes retain their existing atomicity, fencing and correlation rules. The reconciliation
checkpoint is not a hash that must be manually renewed after every write: the admitted incarnation advances through
those guarded operations. Normal retries, scheduler recovery, maintenance and capacity handling remain automatic
under current authorization, with no new routine human approval or manual settings.

## Public outcomes and existing fences

The existing `assertCompatible()`, `assertSameCohort()` and `bindAuthority()` now require reconciliation as well as
versions/capabilities. No Application workflow is duplicated. The
[cohort path inventory](agent-operation-cohorts.md#fence-and-public-path-inventory) remains authoritative.

| Attempt while reconciliation is absent or unverified | Observable result |
| --- | --- |
| New provision/rotation or retained-key service resolution | Sanitized UNAVAILABLE before lookup/generation/reservation, mutation, material or issuance audit; no automatic reissue |
| Revocation, direct Agent persistence or Permission writer (including no-op) | UNAVAILABLE before mutation under the same cohort guard; no bypass through a direct repository path |
| Due discovery or maintenance selection/key accounting | UNAVAILABLE, never a misleading empty queue or zero references |
| Delivery claim/admission/completion | `AgentDeliveryResult::UNAVAILABLE`; no new sensitive invocation before admission; no restored-state success acknowledgement |
| Rewrap/expiry/cleanup | `AgentMaintenanceResult::UNAVAILABLE`; no new material rewrite or cleanup invocation |
| Authorized secret-free status | Existing recorded status only; absence is indeterminate, not proof of rollback; neither status nor authentication grants restoration readiness |

On successful reconciliation the **new** cohort generation also changes the bound authority epoch used by delivery
and cleanup. An admitted pre-restore attempt cannot acknowledge under that generation even if the restored state
revision and consumer epoch are numerically identical. Reclaim/retry still obeys the existing lease, expiry and exact
expected-state rules; reconciliation never extends original retention or authorization deadlines.

A check can race a previously admitted external call. Bytes already materialized may still arrive at their original
fixed sink; cleanup already admitted may still erase an inert entry. Such effects do not authorize activation/use,
restore current authority, or produce a successful stale package acknowledgement. Drain or fence these calls before
certifying reconciliation. Fresh recovery uses the **original** operation/delivery identity and original bytes, not
successor material or the authentication envelope. Current consumer activation and every broker use must separately
validate authoritative state; ordinary package authentication against an unreconciled restored database is not a
substitute for that trusted boundary.

## Consumer-owned stop, reconcile and resume procedure

Use the consumer's actual backup, deployment and storage tools. This procedure defines invariants and evidence, not
SQL, a provider, or a package business workflow.

1. **Stop before replacement.** Close trusted admission and invalidate the incarnation's witness under the conflicting
   cohort fence. Quiesce every issuance, lifecycle, Permission/policy, destination, key, scheduler and maintenance
   writer. Inventory worker and non-HTTP/direct connections; exclude unchecked binaries at storage/trusted admission.
   Fence activation/use too. Drain admitted calls or prove their terminal inert effects before reconciliation.
2. **Retain independent history.** Preserve trusted generation/incarnation identity, operation/audit history, scoped
   deduplication keys, sink receipts and cleanup tombstones, slot high-water/reservations, authority/key state and
   admitted-call outcomes outside the restore target. A backup file alone is neither this inventory nor restore proof.
3. **Restore offline, preferring forward repair.** Only restore current-contract data. Keep admission closed while
   comparing the selected checkpoint with independent history. Use authoritative retained records/journals to recover
   original facts; do not manufacture operation IDs, requests, receipts, audit facts or successor secrets. Missing
   facts that cannot be recovered mean **stop/unavailable**, not guessed absence, a new key or automatic reissuance.
4. **Validate the complete reconciled dataset.** Verify the one supported storage/canonical/destination contract,
   original request bindings and issuance tuples, current Agent revisions/terminal state, audit uniqueness, leases and
   receipts, retired material absence, scoped-key tombstones, slot order and key/authority fences. Preserve the maximum
   independently established order. Confirm cleanup tombstones remain and that no missing local event/receipt was used
   to erase an external fact. Check sink evidence for every retained binding, including late admitted effects.
   Reconciliation reads safe metadata and verifies retained encrypted state; it is not a credential-read facility.
5. **Advance and attest under exclusion.** Persist a strictly newer cohort generation and publish the trusted witness
   for that exact active incarnation only after all evidence agrees. Both repository participants must observe it
   consistently. If either write/acknowledgement is unavailable or uncertain, remain closed; independently verify the
   final identities before resuming, never infer success from the attempted update. A partial generation update safely
   mismatches the witness. Do not reuse a prior generation to avoid retries.
6. **Resume narrowly and observe.** Requalify actual composition/current authority; restart workers without cached
   readiness and exercise status, retained-key resolution and bounded scheduler recovery. Confirm no extra generation,
   audit, order reset, stale claim completion or terminal material resurrection. Then enable ordinary writes and
   independently validated activation/use. Unknown/mismatched evidence, bypassing writers, unexplained sink order,
   unavailable live keys or uncertain reconciliation publication are **abort/hold** conditions. Resume only after
   forward repair and positive verification, never by deleting safety records or enabling an old contract.

Exceptional restoration may require the consumer maintainer's tools and operational authority; this adds no human
approval step to ordinary supported runtime operation. No legacy adoption, schema conversion, old canonical reader
or prior-binary resumption is required or supplied.

## M4 executable evidence

`tests/Application/AccessControl/Agent/Security/AgentRestorationConformance.php` is reusable through
`DeliveryConformanceFixture & AgentRestorationFixture`. The fixture supplies real consumer save/restore/reconciliation
controls and independent persisted observations; the suite calls actual public package services. It must not return
canned Application results or substitute a manually flipped ready flag for reconciliation. Run it alongside
[issuance](agent-issuance-conformance.md), [delivery](agent-delivery-conformance.md) and
[cohort](agent-operation-cohorts.md) conformance, including real authority writer races.

| M4 outcome | Executable package evidence |
| --- | --- |
| Stale state before issuance, before sink outcome commit, after supersession/revocation and cleanup; nine public paths deny with no persisted change/generation/audit/materialization | `test_stale_package_state_never_reopens_unsafe_public_paths` (45 histories/paths, retaining external receipts/high-water separately) |
| Forward repair retains original key/issuance, terminal material absence and nondecreasing order; authorized retry does not issue again | `test_verified_repair_preserves_keys_order_terminal_state_and_identity` |
| Missing/unavailable witness, receipt mismatch, all twelve issuance tuple fields, missing tombstone or order evidence | `test_incomplete_or_mismatched_external_evidence_cannot_enable_repaired_state`; failed reconciliation leaves state unchanged and retry/delivery unavailable |
| Restore after claim/admission/materialization/acceptance; no early invocation or stale acknowledgement, eventual exact original recovery | `test_restore_between_stages_denies_acknowledgement_and_preserves_bytes` |
| Conflicting restore versus live transaction, repeated runtime check rather than startup cache | `test_restoration_contends_with_the_live_writer_fence_and_is_not_a_startup_check` |
| Cleanup tombstones reject delayed replay after repair | `test_reconciled_cleanup_retains_the_sink_tombstone_against_delayed_replay` |
| Reconciliation does not replace current caller/Permission/delegation/destination authority | `test_reconciliation_never_substitutes_for_current_delivery_authorization` |
| Compatible reconciled generation still rejects old delivery/cleanup acknowledgements; maintenance conflict rolls back | `AgentRestorationWriterTest` |
| Same-generation witness validation, missing/stale/unknown generation, both repository participants and authority binding | `AgentOperationContractTest` |
| All eight direct repository writer paths deny unreconciled evidence; service paths and operational reads retain cohort guards | `AgentCohortWriterTest`, expanded `AgentCohortConformance` |

`InMemoryAgentRestorationConformanceTest` and `InMemoryAgentRestorationWithoutLookupTest` run the same scenarios
with and without optional receipt lookup. Their retained latest-package snapshot stands for an independent authoritative
recovery journal; the package snapshot excludes trusted readiness and the sink. It actually
replaces modeled Agent/operation/audit/order state while preserving sink acceptance, receipts, tombstones and
high-water evidence. Reconciliation compares the proposed forward checkpoint with that independently retained
history and verifies safe sink bindings/order. Opaque observation tokens compare complete persisted snapshots without
serializing secret-bearing material. This does **not** establish how a consumer obtains a complete journal, performs
physical restore, authenticates evidence, fences a real process or implements database concurrency. It is not a
production reconciliation algorithm. Package guards and public results, not the fixture implementation, are the API.

## Mandatory external qualification — not executed here

Before supporting an actual consumer, retain its real tool commands/version, source/dependency and adapter identity,
start/end times and exit results, checkpoint identity and restored-instance identity, independently retained evidence
provenance, generation before/after, protected observations and abort/resume outcome. Sanitize logs and never export
raw credentials, ciphertext or key paths as proof.

- Restore a genuinely older current-contract database behind a real sink's accepted and cleaned effects. Independently
  observe no duplicate issuance/audit, no reset/reuse of order/keys and no material resurrection across all M4 histories.
- Race restore/reconciliation with every actual writer on independent connections/processes; attempt stale/restarted
  workers and direct administrative connections. Show that storage replacement cannot bypass the conflicting fence.
- Remove, corrupt, replay or swap the actual trusted witness, storage incarnation, receipt/binding, tombstone and
  high-water evidence. Verify unavailable outcomes with no partial repair/admission. A capability array or local
  boolean is not this qualification.
- Exercise unavailable and uncertain reconciliation publication, restoration tooling failure and abort/hold recovery.
  Demonstrate that generation/witness partial updates remain closed until independently confirmed.
- Delay real admitted delivery and cleanup calls through restoration. Prove fixed-sink inertness, rejected stale
  acknowledgement and fresh exact recovery, plus current activation and broker-use rejection after supersession or
  revocation. Package issuance/delivery success alone proves none of those consumer outcomes.

Passing PHP tests, inspecting migration text, saving a backup, or testing a mock fence does not satisfy these external
requirements. TASK-00059's [integration guide](agent-integration.md) and
[complete evidence inventory](agent-operation-evidence.md) connect the cross-TICKET results and remaining gaps;
consumer adoption, release and deployment remain separately authorized.
