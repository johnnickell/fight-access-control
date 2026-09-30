# Agent credential-operation cohorts (unreleased v0.5.0)

[TASK-00056](../planning/tasks/00056-TASK.md) adds persisted compatibility enforcement to the replacement Agent
protocol. This is an unreleased breaking repository contract, not a migration tool, deployment command, release,
old-binary fence or consumer qualification. Only the [current Agent model](agent-current-contract.md) applies;
there is no legacy mode or adoption transition. The [single canonical contract](agent-canonical-upgrades.md) is supplied by TASK-00057;
[restoration reconciliation](agent-restoration-safety.md) is supplied by TASK-00058 at the package boundary.

## Composition and persisted meaning

Both `AgentRepository` and `AgentOperationRepository` now extend Domain
`AgentOperationContractRepository`. Implement `getOperationContract(): AgentOperationContract` against one
shared authoritative cohort record, qualified against the **actual local composition**. Do not synthesize a current
record when storage is absent, copy a startup-ready boolean, or infer compatibility from implemented PHP interfaces.

The immutable snapshot takes these explicit constructor arguments (no production defaults):

| Field | Current supported meaning |
| --- | --- |
| `storageVersion` | `1`: complete operation, Agent marker, slot reservation, delivery/receipt/claim, maintenance and authority-fence persistence contract |
| `canonicalVersion` | `2`: the sole supported operation-request contract marker, not a selectable creation mode or reader set |
| `destinationVersion` | `1`: immutable registered destination ID/binding revision and cross-scope monotonic write-order meaning |
| `generation` | Durable positive, monotonically advancing cohort generation; increment on every switch, including switch-back |
| `capabilities` | Intersection of persisted qualification and this worker's locally installed supported participants; every capability below is required |
| `reconciledGeneration` | Required nullable projection of independently verified admission evidence for the exact active storage incarnation; must equal the positive persisted generation |

Unknown persisted versions must survive hydration so the package can reject them. `assertCompatible()` rejects
unsupported storage, canonical or destination markers, nonpositive generations, missing capabilities and absent or
mismatched reconciled generations with sanitized `AgentOperationFailure::UNAVAILABLE`. Two participating repository snapshots must also pass
`assertSameCohort()`: both must validate the sole supported contract and agree on generation before forming an
issuance/delivery transaction. Equal generations alone do **not** prove same-database/shared-connection composition.

`getOperationContract()` must throw sanitized UNAVAILABLE, without an infrastructure message or previous throwable,
for missing/unavailable storage, inconsistent settings or an unsupported local participant. A transient outage is
not evidence of compatibility, absence or rollback. No runtime call bootstraps a cohort record or adds manual approval.
The snapshot is not a public HTTP View, Command, Query, Event, credential or consumer policy decision.

TASK-00058 requires the reconciled-generation witness to come from a trusted admission boundary outside the restorable
dataset, freshly verified for the exact live storage incarnation under the same cohort fence. Never copy the stored
generation or infer readiness from a restored flag. Controlled restoration invalidates the witness before replacement;
unknown/unverified evidence projects null. Re-admission requires complete current-contract reconciliation and a newer
monotonic generation, preserving exact bindings, tombstones and order. See the
[restoration procedure and evidence boundary](agent-restoration-safety.md); the package does not detect arbitrary
physical rollback or qualify consumer storage tools.

### Capability ownership

| Required capability | What the consumer must actually supply and qualify |
| --- | --- |
| `operation-storage-v1` | Agent/operation/slot/audit storage, atomic uniqueness, permanent retained keys, exact-state writers and complete authoritative projections |
| `atomic-authority-v1` | The package UoW's shared connection, all repository and current caller/target/delegation/slot/key writers, transaction-duration fences, rollback and commit-uncertainty semantics |
| `bound-cipher-v1` | Separate authentication and delivery encryption, exact issuance-bound materialization and supported live wrapping-key access, without authentication-envelope fallback |
| `ordered-sink-v1` | Server-registered fixed sinks, immutable exact-tuple staging, verified receipts, global delivery identities, persistent high-water/deduplication and inert late effects |
| `material-maintenance-v1` | Authorized rewrap/expiry, shared key-reference admission, exact-tuple inert-entry cleanup and durable cleanup evidence |

The repository adapter's composition qualification must derive this intersection from the persisted qualified profile
and its injected participants, not from the caller or arbitrary flags. Consumers may implement that check through their
composition root; the package supplies no production adapter or universal reflection-based capability detector.
A declaration is **not** conformance evidence. A real consumer must exercise these contracts, including unavailable
and incompatible local participants, before advertising support. Existing runtime authorization, key checks,
`AgentCredentialSink::assertSupported()`, binding checks and receipt verification still execute. Optional receipt
lookup remains optional; it is not a required capability and its absence keeps at-least-once staging semantics.

## Fence and public-path inventory

Inside a package transaction, `getOperationContract()` acquires the shared cohort fence on the **same connection**
and holds it through commit or rollback. Acquire this fence before subordinate authority, Agent, operation,
destination and key fences. Cohort switches take a conflicting fence; a startup check never authorizes later work.
Every direct repository write independently enforces the same rule, even when no service guard called it first.
Outside a transaction, the method performs a fresh authoritative read without writes or a commit. That observation
is not a future authorization grant. Use a consistent global lock order; never start nested transactions here.

| Boundary / actual package path | Guard and required participation |
| --- | --- |
| `AgentProvisioningService::provision`, `AgentCredentialRotationService::rotate` | Check both repositories before current authorization, lookup or generation in every transaction/retry. Retained-key resolution still precedes new-work capacity; only an absent key reaches new-work admission using the fixed canonical contract. |
| `AgentRepository::add`, `replace`, `replacePermissionAssignments` | Enforce the cohort on direct writes too, with unconditional correlation/cancellation and no raw-return fallback. Agent/operation/audit state remains one transaction. |
| `AgentCredentialLifecycleService::revoke` | Guard before loading/mutating authority; original cancellation/audit and post-commit publication/rethrow behavior are unchanged. There is no alternate authority mode. |
| Grant/revoke/replace Agent Permission handlers and their coordinator | Guard before target lookup/transition, including desired-state no-ops. Current ADMIN_SAFE checks, expected revisions and consumer caller policy still apply. |
| `AgentOperationRepository::reserveDestinationWrite`, `add`, `retireCredential` | Enforce the cohort before reservations, correlation/material creation and lifecycle cancellation. No external direct caller may omit it. |
| `AgentCredentialDeliveryService` and `replaceDelivery` | Fresh compatible same-cohort transaction for claim, admission and outcome, including material-free/recorded outcomes. Recheck compatibility before outside-transaction receipt lookup/materialization. |
| `ListDueAgentDeliveriesHandler`, `AgentDeliveryRecoveryService` | Discovery checks compatibility after initial current discovery authorization. Each subsequent delivery performs its own fenced checks; selection is not admission. |
| `AgentDeliveryMaintenanceService`, `replaceMaintenance` | Guard each rewrap/expiry/cleanup-admission/cleanup-ack transaction. Rewrap and key references stay fenced; check compatibility again before outside-transaction cleanup. |
| `ListAgentDeliveryMaintenanceHandler`, `CountAgentDeliveryKeyReferencesHandler` | Check before authoritative read selection/count. No mutation, commit, decryption or permission to destroy keys. |
| `GetAgentOperation`, ordinary Agent safe reads and authentication | Preserve existing current authorization and recorded-version validation. No new write-cohort admission is granted by a successful retained-operation read/authentication. |
| Consumer authority writers | Caller/target/delegation revocation and regrant, Permission/Role/User policy changes, managed tier replacement/removal, destination binding/ownership, key write-admission and cohort control must share the fence. Inventory actual non-HTTP, worker and administrative paths too. |

Package Permission tier and reference contracts still govern `PermissionRepository::replace/remove`, managed-policy
reconciliation and related Role/User writes. Where those writes affect credential-operation policy, consumer adapters
must acquire the cohort fence and advance the corresponding authority epochs on the shared transaction. They are not
exempt because they run outside Agent handlers. Consumer inventory and real cross-writer races must establish that
participation; this package does not add a second authorization policy engine or a production persistence adapter.

### Switches around admitted external work

The package binds each delivery admission/outcome and cleanup admission/ack authority epoch to the persisted cohort
generation (`SHA-256(generation + ':' + consumer epoch)`), preserving the original expiry. A compatible switch or
switch-back therefore cannot acknowledge an older admission. This does not replace consumer worker/policy/delegation
epochs. It does not extend a deadline, authenticate a caller or authorize activation/use.

A switch after claim but before admission may admit under the newly compatible cohort. An incompatible switch before
invocation prevents material access. If bytes were already admitted/materialized or a change races the final check,
the existing fixed-sink contract allows only inert late effects; outcome checks cannot acknowledge stale authority.
No deployment can recall disclosed bytes. Fresh authorized recovery uses original delivery identity/bytes and the
existing receipt-first path, not new issuance. Cleanup may similarly finish an already admitted inert erasure, but its
stale acknowledgement rejects and must be reconciled through a fresh authorized attempt.

Do not accept unknown or unbound persisted epochs as current admission evidence. Keep lease, receipt, tombstone
and retention evidence; fresh recovery still obeys existing lease rules. Initial adoption does not require migration
of nonexistent pre-cohort admissions. Exact persisted representation and runtime fencing remain consumer-owned.

## Initial adoption and runtime cohort changes

There are no deployed consumers requiring canonical-version upgrades. Initial composition qualifies the single
supported contract; do not create an old/new canonical rollout or reader-retention process. The procedure below
applies to real runtime cohort replacement and existing authority, not a requirement to manufacture legacy history.

### Controlled cohort switch procedure

1. Inventory every writer above, pending/admitted operations, current Agents, authority epochs, envelope keys,
   destination reservations, sink receipts/high-water marks and permanent operation/sink tombstones. Rehearse with
   the actual consumer's tools before adopting; package fixtures cannot certify this inventory.
2. Quiesce issuance, lifecycle, Permission/policy, delivery, maintenance and background/admin writers. Drain or fence
   admitted calls at the trusted sink boundary, preserving inert-effect constraints and current activation/use checks.
3. **Revoke old binary access** at storage or a trusted boundary it cannot bypass. Check direct connections,
   credentials/pools, restarted workers and non-HTTP jobs. A new-code version check cannot stop code that never runs it.
4. Preserve Agent authentication envelopes and terminal state. Install complete operation/authority/key persistence
   and same-connection capabilities. Persist the explicitly qualified settings and next generation under the exclusive
   cohort fence. Never manufacture issuance/correlation or silently choose defaults when a record is missing.
5. Qualify actual composed repositories/UoW/authorization/ciphers/sinks and all authority writers with real races.
   Ensure live keys, sink ordering/readiness and the single supported canonical contract are valid.
6. Enable bounded discovery, then sensitive admission only for the qualified cohort. Current caller authorization and
   finite defaults remain automatic; routine recovery needs no additional human approval or manual configuration.
7. Prefer forward repair after new-contract effects. A compatible rollback must preserve versions, generation,
   correlation, receipts, order and tombstones with writers fenced. Do not restore an old database and reset generation.
   Follow [TASK-00058's restoration procedure](agent-restoration-safety.md): invalidate independent admission before
   replacement and attest the exact reconciled incarnation at a newer generation. No check detects arbitrary rollback.

Previous-contract writers, historical migrations and automatic compatibility bridges are unsupported. Unsupported
cohorts remain unavailable rather than falling back to raw issuance, unknown canonical rules or secret disclosure.
Original publication-warning scope (provision/rotation only), finite bounds, capacity-preserved authorized recovery,
separate delivery/activation/use outcomes and current policy are unchanged.

## Executable evidence and consumer obligations

`tests/Application/AccessControl/Agent/Security/AgentCohortConformance.php` composes actual package services through
`DeliveryConformanceFixture & AgentCohortFixture`. A consumer binds its real ports and persisted observations, supplies
`breakCohort()` by changing actual storage/composition, and `switchCohort()` through its conflicting writer fence.
Do not emulate failure by returning canned Application results. `restart()` must discard process-local readiness while
retaining real storage, receipt and authority evidence. A contention hook may observe blocked execution or a retryable
conflict, but the competing switch must not commit through the held transaction.

| M2 scenario | Package evidence |
| --- | --- |
| Missing storage/outage, unsupported contract markers, every absent capability across eight public paths | `test_incompatible_restarted_workers_do_not_mutate_or_materialize` asserts unchanged Agent/operation/material/order/audit/generation and no sink/decryption; status remains confirmed rather than absent |
| Compatible creation/rotation, scheduler delivery, rewrap and revocation | `test_compatible_cohort_retains_real_rotation_delivery_maintenance_and_revocation` |
| Switch at claim/admission/materialization/sink acceptance; compatible and incompatible restart | `test_cohort_switch_never_acknowledges_stale_admission_and_restart_recovers` |
| Transaction versus switch, no startup cache | `test_cohort_switch_contends_with_live_transaction_not_just_startup_check` |
| Direct repository writers; current revocation; Permission changes and no-ops; mismatched repository cohorts | `AgentCohortWriterTest` |
| Cleanup switch-back and maintenance contention/rollback | `AgentCohortWriterTest` |
| Single supported contract and generation-bound authority rules | `AgentOperationContractTest` |

The reference runner is `InMemoryAgentCohortConformanceTest`. It models persisted state and deterministic
interleavings. Its capabilities are explicit **test fixture composition**, not real database/cryptographic/sink
qualification. Existing [issuance conformance](agent-issuance-conformance.md) and
[delivery conformance](agent-delivery-conformance.md) retain uncertainty, capacity, optional lookup, authorization,
publication and key/sink failure coverage. Product tests exercise public behavior; no schema/config-text test replaces it.

Before a consumer can claim M2 qualification, additionally retain these **mandatory unexecuted external scenarios**:

- For an actual runtime replacement (not first adoption), attempt every excluded issuance, rotation/revocation,
  Permission/policy, delivery and maintenance writer with its old
  binary and credentials after exclusion, including restart and direct storage/non-HTTP entry. Observe denied access
  and independently unchanged authority, operation, audit, slot and key-reference state. Installing new code alone fails.
- Race the cohort switch with each real authority, lifecycle, destination and key writer on independent connections
  and processes, including rollback and revoke/regrant ABA. Prove one fenced order and exact state; no local boolean
  or mock ordering qualifies transaction participation.
- Remove or substitute each actual incompatible repository, transaction/authorization, cipher and sink participant,
  including a restarted old worker. Observe denial before mutation/materialization and sanitized failures. A manually
  edited capability array alone does not prove composition checking.
- Drain/fence real admitted calls during the switch; verify that late bytes never activate enrollment or authorize
  broker use. Run current activation/use authorization independently of issuance/delivery status.

No such real-consumer, old-binary, deployment or restore evidence is supplied by this package checkpoint. Independent
package acceptance and consumer adoption are separate; no release or migration is authorized here.
