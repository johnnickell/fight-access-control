---
id: TICKET-00014
epic: EPIC-00009
title: Preserve current credential-contract safety and evidence
status: in-progress
---

# Preserve current credential-contract safety and evidence

## Current Package-wide Amendment — 2026-09-30

John rejects all extra code supporting previous package iterations, not just historical canonical readers.
[ADR 0011](../adr/0011-pre-v1-current-contract-only.md) supersedes this TICKET's earlier existing-Agent, upgrade and
migration requirements and the corresponding pinned-proposal requirements. No prior API/data format or binary must
remain supported. [TASK-00068](../tasks/00068-TASK.md) removes the remaining compatibility code across the package;
TASK-00058 waits for that cleanup and proves current-contract restoration only. TASK-00059 supplies current integration
guidance and evidence, not a migration route. Completed slices and checkpoint receipts stay historical; they do not
require retaining obsolete code. M1 below now tracks removal rather than preservation of legacy behavior.

M2/M3 retain only current validation, writer fences and retry/restart safety. M4 retains stale current-state restore
safety after external effects. Mark superseded scenarios as superseded, not failed or passed; no migration rehearsal,
legacy adoption or mixed-version rollout is an acceptance obligation. Real current adapter/sink/authority and restore
qualification remain separate from package proof. No consumer data reset or release is authorized.

## Earlier M3 Amendment — 2026-09-30

John confirmed there are no consumers or persisted Agent operations requiring historical/backward compatibility.
[TASK-00057](../tasks/00057-TASK.md#current-decision--supersedes-the-cross-version-requirements) is reopened to retain
the best Unicode-aware canonical behavior as one supported contract and remove historical readers/version switching.
This replaces M3's cross-version acceptance obligation and the corresponding proposal-matrix row; preserving current
keys, runtime authorization, atomicity, cleanup/order and unknown-state rejection remains required. Old M3 acceptance
at `04a2adf` and PR #93 publication are historical evidence only, not acceptance of the revised scope. Restoration
and final guidance consume the one current contract; no old canonical reader or compatibility shim is required.
Other completed slices are not reopened by this bounded amendment. At the planning checkpoint implementation and
fresh review were outstanding; John's subsequent work invocation authorizes implementation/local commit only.

### Revised M3 implementation checkpoint — before independent review

TASK-00057 now uses one Unicode-aware canonical rule/marker `2`, no-argument request canonicalization and a single
cohort canonical marker instead of creation/reader settings. The v1 reader and cross-version matrices are removed;
retained-key/restart/lifecycle and current authorization/admission safety remain. Updated public guidance and the
`AgentCanonicalConformance` evidence map describe initial adoption, not migration of nonexistent history.
The full local gate passes **1469 tests / 26053 assertions**, exact **6315/6315 statements**; focused Agent/OpenAPI
checks pass **941 tests / 21822 assertions**. No final warnings/skips or dependency drift. The TASK owns logs/receipts
and failure chronology. M3 remains unchecked pending new independent review; original acceptance below is historical.
No push, PR editing, merge, release or consumer qualification is claimed.

### Revised M3 independent acceptance and landing intake — 2026-09-30

Independent review accepted revised TASK-00057 at `2de41046dfe49a96340645a0f1c08c3ea2e2ac30` against unchanged
`develop` `f0d872c`, with C1–C8 and all applicable Spec/Standards IDs passing and no findings. Fresh reviewer checks
pass **941 tests / 21822 assertions**; all **771/771** saved final gate inputs match. The full local gate passes
**1469 tests / 26053 assertions**, exact **6315/6315 statements**, with no final warnings/skips. M3 is accepted at
the package boundary, superseding the original cross-version acceptance rather than reusing it.

John requested landing of this revision through existing PR #93. At the completion checkpoint final publication is
pending; the TASK's ignored simplification landing handoff owns fresh gates, final metadata/remote identity and the
administrative-only bridge. Hosted CI is optional/unchecked; no interactive QA is required. M4/M5 and real consumer
qualification remain outstanding, and no merge, release or adoption is claimed.

## Problem and Outcome

The current recoverable Agent contract must prevent duplicate issuance and stale authority after retry, restart or
restoration behind external sink effects. It needs one supported API/data model, current writer/admission guards and
traceable evidence, not support for earlier package iterations.

This is the third approved requirement area under [EPIC-00009](../epics/00009-EPIC.md), targeting `v0.5.0`. It owns
current-contract safety, integration guidance and evidence traceability. It does not execute consumer migrations,
qualify an untested consumer, release the package or take over the issuance/delivery tests owned
by [TICKET-00012](00012-TICKET.md) and [TICKET-00013](00013-TICKET.md).

## Use Cases

| Actor and trigger | Commands/service intent | Queries | Events and expected effects |
| --- | --- | --- | --- |
| Consumer composes the current Agent contract | Current repositories and validated hydration | Safe Agent/operation reads | No legacy mode or adoption path. Current records retain exact authority/correlation; unknown or inconsistent state rejects without manufactured issuance. |
| Consumer rolls out a new compatible cohort | Consumer-owned deployment/admission controls | Capability/contract-version readiness and safe status | Missing capabilities and incompatible/restarted old writers cannot mutate new authority. No new business Event is introduced by deployment. |
| Caller retries a retained or tombstoned current-contract operation after restart | Original request/key through replacement service | Authorized operation lookup/resolution | The single supported canonical contract preserves equality/conflict and original issuance; unsupported markers deny without fallback or secret access. |
| Consumer restores/rolls back after external sink acceptance | Consumer-owned quiesce, restore and reconcile tools | Authoritative operation/receipt/order evidence | Preserve deduplication and ordering; incompatible restoration remains unavailable rather than duplicate issuance or revive retired state. |
| Integrator qualifies persistence, authorization and sink adapters | Run public consumer-bindable behavior suites and deployment rehearsals | Evidence inventory and gaps, not a new product Query | Distinguish package proof from real adapter proof and consumer activation/use authority. No publication/upgrade follows merely from a passing package suite. |

There is no new package migration, release, activation or launch Command, Query or Event. Runtime operation inputs,
status and safe facts are owned by TICKET-00012/00013. Deployment tools are a consumer concern, not a reason to make
Domain/Application depend on an Adapter layer.

## Current Data and Breaking API Contract

- Remove retired signatures, including rejection-only stubs, and the legacy/non-recoverable Agent mode under
  TASK-00068. No old data/API preservation or adoption transition is required. Update current callers directly.
- Every current lifecycle entry point obeys operation correlation, fences and cancellation. Validated hydration
  preserves current authority without issuing anything; missing/corrupt state is not a legacy fallback.
- Never invent keys, destinations, operation/delivery/receipt bindings or copy an authentication envelope into
  delivery storage. These are current safety requirements, not reasons to retain a migration path.
- Preserve the single-active-credential, immediate replacement, terminal revocation and authentication nonce/current-
  authority invariants in [ADR 0004](../adr/0004-agent-hmac-credential-lifecycle.md) and
  [WF-002](../wayfinder/tickets/WF-002-agent-credential-revocation-lifecycle.md). Permission-tier administration and
  the separate MCP integration are not changed.

## Superseded Deployment and Migration Requirements

The following numbered rollout plan is retained as historical scope only under ADR 0011. It is not an executable
migration or acceptance requirement. Current requirements are the M2–M4 criteria below and TASK-00058: validate one
contract/composition, fence current writers and reconcile stale same-contract state against external effects. No
old-writer resumption, mixed-version rollout, data conversion or prior-envelope preservation is required.

1. **Inventory and rehearse:** inventory active/legacy Agents, pending delivery, keys, lifecycle/authorization writers
   and existing external sink effects. Rehearse backup/restore using the consumer's actual migration/deployment tools.
   Quiesce/fence old issuance, rotation/revocation, delivery and authority writers before switching; drain or fence
   already-admitted calls. Maintenance cannot leave old authority writers bypassing new fences.
2. **Prepare compatible storage/composition:** add operation/request versions, delivery/receipt/claim/admission data,
   destination/authorization fences and uniqueness/version constraints while preserving authentication envelopes.
   Compatible repositories and authorization participation share the package connection; cipher/sink capabilities
   honor the accepted contracts. Missing storage/capabilities reject startup/admission, never fall back to legacy.
   A specific schema/DDL/ORM choice remains consumer-owned.
3. **Switch one controlled cohort:** mixed legacy/new lifecycle or delivery writers against shared authority are
   unsupported. Persisted contract-version checks deny incompatible/restarted workers. Fence old binary credentials/
   access at storage or a trusted admission boundary: new-code-only checks cannot stop an old binary. Compatible
   canonicalizer versions must agree on new-key creation rules. Enable scheduled discovery and only enable sensitive
   admission after migration, conformance, sink fencing and key readiness. No zero-downtime guarantee is made.
4. **Preserve current-contract request meaning:** look up the authorized scoped key, including retained tombstones,
   before new-work admission. Use one Unicode-aware canonical contract with its persisted marker and safe request.
   Equivalent retries resolve and changed bindings conflict; unsupported/corrupt markers deny without disclosure,
   migration or new-key fallback. Retention/cleanup cannot reinterpret destinations or make issued keys reusable.
   Historical readers and multi-version creation/switch-back support are removed under the 2026-09-30 M3 amendment.
5. **Prefer forward repair after new effects:** never roll back to legacy writers or drop operation/fence evidence
   after a new-contract mutation. A supported restoration preserves the exact current persisted contract, bindings
   and fences with writers quiesced; it does not require support for older canonical versions. Restoring an older database alone is unsafe after sink acceptance. Reconcile
   receipts, deduplication tombstones and high-water evidence before admission; otherwise remain unavailable for
   reconciliation. Before any new-contract state/effects, a fenced legacy cohort may resume only after proving none
   exist. Backup restoration must not reset destination order or resurrect retired keys.

## Permissions, Validation and Public Guidance

Current composition/deployment authority is consumer-owned; no package Permission names or runtime human-approval framework
are added. Every runtime status, retry, discovery, admission, acknowledgement and activation/use decision retains
current authorization. Tooling/maintenance permission is not permission to disclose or activate a credential.

Validate contract versions, retained canonical-request meaning, data markers and required capabilities through
observable outcomes. Unsafe or unknown state rejects without fabricated history or secret access. Real consumer
conformance must prove authority-writer participation under concurrency, including non-HTTP paths; external cached
allow decisions and partial writer upgrades cannot pass by documentation alone.

Public documentation must identify `v0.5.0` as the planned breaking replacement, describe the current APIs and
new persistence/authorization/sink obligations, and distinguish confirmed issuance, publication warning, credential
delivery, activation and launch/use authority. Describe finite defaults and optional validated overrides supplied by
TICKET-00012/00013, clear retryable new-work capacity outcomes and preservation of existing recovery. Do not require
manual configuration or extra approval for routine operation; do not generalize the D3 exception to revocation or
other handlers. Concrete values and implementation choices must be documented and tested before implementation
acceptance. Reconcile README, CONTEXT, project guidance, current integration documentation and changelog against the implemented
public contract without rewriting historical completed records or claiming release/adoption.

## Scenario Ownership and Evidence Traceability

This maps **every row** in the [proposal's required behavior matrix at `ecd849e`][proposal] to a behavior owner.
ADR 0011 explicitly supersedes previous-iteration requirements; all other detailed requirements remain. Labels
I1–I7 and D1–D7 refer to acceptance items in TICKET-00012 and TICKET-00013, not new planning IDs. M1–M5 are the acceptance items below. A shared row requires
both sides' proof; tests stay with their business owner rather than being deferred to this TICKET.

| Proposal scenario | Owning acceptance and required evidence |
| --- | --- |
| Normal provision and rotation | TICKET-00012 I1: one mutation/audit and prepared delivery; TICKET-00013 D1/D3: actual delivery is a separate confirmed outcome. |
| Commit succeeds, response is lost, then caller/service restarts | TICKET-00012 I2: same-key original outcome, no repeated generation/mutation, including original rotation predecessor. |
| Indeterminate issuance commit, for both provision and rotation | TICKET-00012 I3: both committed and rolled-back cases resolved from storage after restart. |
| Indeterminate admission or delivery-outcome commit | TICKET-00013 D3: no unconfirmed materialization; current receipt reconciliation, not blind redelivery. |
| Success event publication throws after commit, for both provision and rotation | TICKET-00012 I4: ratified D3 returns confirmed metadata with typed sanitized warning, even when failure publication also fails. |
| Both publishers fail and caller never retries | TICKET-00012 I4 plus TICKET-00013 D1: terminate caller; restarted authorized scheduler discovers/delivers, with stable facts and no duplicate audit. |
| Pre-commit audit, encryption or persistence failure | TICKET-00012 I3: no partial Agent/operation/delivery/audit or false committed success. |
| Same key, changed name/target/predecessor/kind/destination | TICKET-00012 I2: conflict without mutation/disclosure; canonical equivalence tested separately. |
| Wrong caller/namespace, revoked authorization, expired delegation | TICKET-00012 I5 plus TICKET-00013 D1/D2: current authority on new work, retry, status, discovery and delegated original-scope resolution. |
| Equal opaque operation IDs across scopes at one sink | TICKET-00012 I5 plus TICKET-00013 D4: distinct delivery IDs, rejected unauthorized ownership, monotonic cross-scope reassignment order. |
| Caller/delegation/destination authority changes around admission | TICKET-00013 D2: all temporal interleavings, expiry and revoke/regrant ABA; real consumer activation/use conformance also required. |
| Authorization and lifecycle writers share the transaction fence | TICKET-00012 I5 plus TICKET-00013 D2/D5: public transaction-conformance scenarios and real adapter runs, not merely in-memory ordering. |
| Concurrent identical and conflicting requests | TICKET-00012 I2: one scoped-key winner, and at most one rotation successor across keys. |
| Store unavailable; store accepts then response is lost; delivery outcome commit fails | TICKET-00013 D3: recoverable original ID/bytes, no delivered result without receipt or fallback issuance. |
| Concurrent delivery retry and lease takeover | TICKET-00013 D3: advancing claim fences, stable delivery ID/bytes and no stale acknowledgement. |
| Different operations deliver out of order | TICKET-00013 D4: delay before/after newer sink acceptance, cross-slot rotation and cross-Agent/scope reassignment; inert old material cannot authorize use. |
| Receipt or idempotency binding mismatch | TICKET-00013 D4: every tuple field/bytes mismatch and missing/unverifiable receipt reject without delivery/activation. |
| Revocation/supersession before and during invocation | TICKET-00013 D2/D5: retired credentials deny admission/completion and original status never resolves to a newer secret. |
| Delivered-secret loss, retention expiry and replay after tombstoning | TICKET-00013 D6 plus TICKET-00012 I6: no re-read/revival/duplicate issuance; retained sink and operation evidence withstand delayed replay. |
| Decryption/key access fails after issuance | TICKET-00013 D6: temporary recovery, permanent failure, corrupt/swapped material and rewrapping preserve the original binding without envelope fallback. |
| Every retained lifecycle entry point races delivery | TICKET-00013 D5 plus TICKET-00012 I7: atomic cancellation through every current path. ADR 0011/TASK-00068 removes old signatures rather than retaining rejection stubs; no invented bindings. |
| Existing-Agent upgrade and incompatible consumers (partly superseded) | ADR 0011 removes legacy preservation/adoption; M1/TASK-00068 proves removal. M2 retains current composition validation and stale/unsupported writer rejection, not old-version support. |
| Canonical-request versions across upgrade/restart (superseded scope) | 2026-09-30 amendment: M3 now proves one best current contract across retry/restart/cleanup, rejects unsupported markers and removes historical-reader/version-switch machinery; no cross-version upgrade proof required. TICKET-00012 I6 retains runtime correlation ownership. |
| Rollback/restore after external acceptance | This TICKET M4 with TICKET-00013 D4/D6: no reset/resurrection, receipt/tombstone reconciliation or unavailable state; real restore rehearsal required for adoption. |
| Safe representations and sensitive-value lifetime | TICKET-00012 I7 plus TICKET-00013 D6: safe serializable/debug/event/audit/failure surfaces, fixed sensitive destination and no secret-read capability. |

### TASK-00049 issuance evidence checkpoint

The [consumer-bindable suite and I1–I7 evidence map](../../docs/agent-issuance-conformance.md#evidence-map) now connect
these rows to executable public package paths. The latest results and local gate receipt are recorded in
[TASK-00049](../tasks/00049-TASK.md#implementation-and-verification-checkpoint). Method names below belong to
`IssuanceRecoveryConformance`, run by `InMemoryIssuanceRecoveryConformanceTest`; predecessor tests remain identified
in the linked map. Independent review accepted TASK-00049 implementation `3fcf2c5` with all ten criteria passing
and no findings. This is accepted package behavioral-adapter evidence—not real database, sink, activation/use,
migration or restoration qualification.

| Proposal rows covered in this checkpoint | Executed scenario references |
| --- | --- |
| Normal issuance; response loss/restart; both publishers fail and caller never retries | `test_both_publishers_and_caller_can_disappear_before_scheduler_only_recovery` (both operations, pending and abandoned claims); `test_same_key_retry_preserves_fact_identity_and_current_authority` |
| Indeterminate issuance commit; precommit audit/persistence/authority failure | `test_indeterminate_commit_resolves_original_request_after_restart`; `test_precommit_failure_rolls_back_every_participant_and_reservation`; predecessor encryption-fault tests in the I3 map |
| Changed request; concurrent identical/conflicting keys; competing rotations | `test_retained_binding_conflicts_and_unknown_versions_never_issue`; both `test_contending_*` key/binding scenarios; `test_competing_rotation_keys_cannot_both_replace_one_predecessor`; reference contention is serialized, earlier suites inject loser rollback/CAS |
| Wrong caller/namespace, revoked authority, delegated discovery, transactional writers | `test_untrusted_scope_and_destination_cannot_read_or_resolve_an_existing_key`; `test_authority_writer_and_issuance_have_one_fenced_order`; `test_discovery_requires_delegation_and_cannot_cache_admission_authority`; `test_outer_transaction_cannot_wrap_an_issuance_service` |
| Equal IDs across scopes, slot reassignment/order | `test_equal_ids_in_different_scopes_share_monotonic_slot_order_not_issuance` |
| Sink acceptance with response loss; original ID/bytes | `test_lost_sink_response_recovers_original_bytes_and_id_not_issuance`; TASK-00052 retains receipt-first and all delivery commit-boundary proof |
| Revocation/supersession, original status, current authority | `test_actual_delivery_then_revocation_retains_original_status_without_authority`; `test_rotation_and_lifecycle_writer_never_leave_a_recoverable_revoked_credential`; consumer activation/broker checks remain unexecuted |
| Terminal delivery, retention expiry, tombstone replay | `test_terminal_delivery_retains_key_without_raw_or_envelope_fallback`; `test_expiry_and_cleanup_never_make_original_key_fresh_issuance` uses actual maintenance and rejects delayed sink staging |
| Safe representations and sensitive-value lifetime | Shared serialization/JSON/debug/failure assertions plus I7 predecessor safety tests; no production logging/cryptography qualification inferred |
| D3 outcome separation and D4 bounded defaults/overrides/capacity | Combined publication/pending/delivered status assertions; `test_capacity_preserves_status_resolution_and_bounded_recovery`; current-principal revalidation is not enrollment or launch authorization |

TASK-00054 adds wider delivery/lifecycle conformance at the checkpoint below. TASK-00055–00059 still own
compatibility, cohorts, cross-version canonicalization, restoration and final complete traceability. Real adapter
races/process termination, consumer activation/use and deployment rehearsals remain explicit missing adoption evidence.

### TASK-00054 delivery and lifecycle evidence checkpoint

The [consumer-bindable suites and complete D1–D7 evidence map](../../docs/agent-delivery-conformance.md#evidence-map-d1d7)
now connect the delivery rows to actual package operations, original status and independent persisted observations.
At the implementation checkpoint the new reference runners pass **186 tests / 5061 assertions**, covering receipt
lookup and non-lookup recovery profiles. Full-gate results and retained logs/receipt belong to
[TASK-00054](../tasks/00054-TASK.md#implementation-and-verification-checkpoint). Independent review accepted
`7b58fbe` with all ten criteria passing, no findings and all 734 gate inputs verified. TASK-00054 is done for accepted
package implementation/local verification; John requested PR landing, not merge or release. This is
package-controlled modeled-interleaving evidence, not a real database, sink, cryptography or consumer pass.

| Proposal rows covered by TASK-00054 | Executed references in the reusable suites |
| --- | --- |
| Normal separate delivery; scheduler restart; bounds/current reservation | `DeliveryLifecycleConformance::test_default_restart_skips_an_entire_obsolete_batch_without_manual_cleanup`; TASK-00049 retains combined both-publishers/caller-termination ownership |
| Indeterminate admission/outcome; persistence failure | `test_restart_resolves_each_uncertain_delivery_commit_without_reissuing` (both issuance kinds, three stages, both commit outcomes); `test_each_failed_persistence_stage_rolls_back_and_scheduler_recovers` |
| Current caller/Permission/delegation/destination changes; shared writers; expiry/ABA | `test_authority_changes_deny_early_or_leave_only_inert_late_bytes`; `test_fenced_authority_writer_and_aba_require_a_new_admission`; `test_earliest_authority_deadline_guards_materialization_invocation_and_completion` |
| Lost sink response; concurrent retries and lease takeover; delivered-secret loss | `test_lost_response_takeover_and_delivered_secret_loss_never_trigger_secret_reread`; receipt-aware recovery avoids key access, non-lookup repeats only with fresh admission |
| Every retained lifecycle path; retired original status; authentication fencing | `test_every_retained_lifecycle_writer_fences_real_delivery_and_original_status` (four service/direct paths × five boundaries); `test_revocation_publication_failure_remains_a_throw_after_durable_retirement` |
| Equal IDs across scopes; out-of-order/cross-slot rotation/reassignment | `ProtectedSinkConformance::test_actual_rotation_orders_late_predecessors_across_slots_without_selecting_old_authority`; `test_equal_operation_ids_and_reassigned_slots_keep_global_order_and_exact_receipts` |
| Receipt/idempotency mismatch and repeated bytes | `test_every_immutable_receipt_or_idempotency_field_and_bytes_reject_mismatch` (12 tuple fields plus bytes); missing/swapped receipts also tested |
| Key restoration/loss/corruption/swaps; rewrap; retention and cleanup replay | `test_rewrap_key_failure_and_restart_preserve_original_binding_without_envelope_fallback`; `test_rewrap_during_delivery_fences_stale_completion_and_retains_retry_history`; `test_retention_cleanup_and_replay_preserve_correlation_and_slot_order` |
| Safe serialization/debug/audit/events/failures; bounded recovery at capacity/outage | Shared safe-surface assertions with exception arguments enabled; `test_invalid_overrides_reject_without_work`; `test_valid_overrides_pin_retry_retention_and_cleanup_without_manual_approval`; `test_capacity_policy_and_storage_outage_do_not_disguise_existing_recovery` |

The guide maps every D1–D7 detail to these suites and retained predecessor focused tests. Real consumer binding must
run independent transactions/processes, actual sink/key adapters and all authority writers, then separately prove
fresh transactional activation and each broker use; those runs are not present. Migration/cohort/canonicalization/
restoration and consumer adoption remain with TASK-00055–00059 and the owning consumer, not this reference pass.

Additional upstream-ratified requirements: TICKET-00012 I1/I4 and TICKET-00013 D2/D4 prove the D3 separation of
issuance/delivery/activation/launch authority. TICKET-00012 I6 and TICKET-00013 D7 prove D4 default/override, capacity/
existing-recovery and cleanup behavior. M5 verifies these are included in the final traceability/evidence inventory.

### TASK-00056 cohort evidence checkpoint

The [cohort contract and M2 evidence map](../../docs/agent-operation-cohorts.md) now connect compatibility checks to
actual provision/rotation, revocation, discovery/delivery, maintenance and direct repository/Agent Permission paths.
`AgentCohortConformance` is consumer-bindable; the reference runner models persisted state and controlled switches,
including restart and generation-bound rejection of stale delivery/cleanup acknowledgements. Domain and focused
writer tests cover version/capability rejection, legacy/no-op bypasses, mismatched repositories and no key/sink effects.
The local gate passes **1442 tests / 21365 assertions**, exact **6307/6307 statements**; focused cohort/contract checks
pass **124 tests / 3196 assertions**. Independent review accepted `ff5c035` with all eight TASK criteria passing,
no findings, **913 fresh Agent tests / 17039 assertions** and all **764 final gate inputs** verified. TASK-00056 is
done for accepted package implementation/local verification; M2 below is accepted at that boundary. John's landing
request published [PR #92](https://github.com/johnnickell/fight-access-control/pull/92) against unchanged `develop`
at initial head `a0897e6`, retaining the full-gate counts above. Its ignored handoff owns final metadata/remote
verification and the metadata-only bridge.
Real old-binary exclusion, participant substitution, all authority-writer races and consumer activation/use remain
mandatory unexecuted adoption evidence. No schema/migration, merge or release is authorized.

### TASK-00057 canonical upgrade evidence checkpoint — historical, superseded by M3 amendment

The [canonical upgrade contract and M3 evidence map](../../docs/agent-canonical-upgrades.md) now connect permanent
historical-reader retention to actual provision/rotation/status and delivery/retirement/cleanup paths. V1 retains
frozen ASCII-edge name rules; v2 adds a fixed Unicode edge-whitespace set for newly issued provisioning requests.
Both versions preserve original equality/conflict after creation switches and fresh service composition. Cohort
participants must agree on creation/reader settings and generation; historical reads do not authorize a creator.
The full local gate passes **1501 tests / 32816 assertions**, exact **6317/6317 statements**; focused Agent checks
pass **972 tests / 28487 assertions**. Independent review accepted `04a2adf` with all eight TASK criteria passing,
no findings, **973 fresh tests / 28585 assertions** and all **771 final gate inputs** verified. TASK-00057 is done
for accepted package implementation/local verification; M3 is accepted at that boundary. John subsequently requested
PR landing; the TASK's ignored handoff owns publication identity and the administrative-only acceptance bridge.
Reference fixtures model persisted state/restart, not an actual consumer schema migration, old-binary exclusion,
database/process restart or rollout qualification. TASK-00058/00059 retain restoration and final evidence ownership;
no merge or release is authorized.

### TASK-00058 restoration evidence checkpoint — before independent review

John authorized the main checkout from `develop` `80e9add` on `feature/task-00058-restoration-safety`.
[Restoration safety](../../docs/agent-restoration-safety.md) now requires independently reconciled-generation evidence
through the existing cohort boundary, for the exact active storage incarnation under the shared writer fence.
Missing/mismatched evidence denies unsafe effects; restored local flags do not prove readiness. Re-admission advances
generation, preventing stale delivery/cleanup acknowledgement. No restore Command or migration engine is introduced.

`AgentRestorationConformance` exercises actual public paths with and without receipt lookup: snapshots before issuance,
sink outcome, supersession/revocation and cleanup; missing/exact-binding/order/tombstone evidence; forward repair,
retained-key replay and current authority; late admitted bytes and restoration contention. Separate writer tests
cover same-state-revision stale delivery/cleanup acknowledgements and rewrap rollback. Reference fixtures model an
independent recovery journal and retain external sink state across actual modeled package replacement, not a real
consumer restoration algorithm, physical restore, external authority or activation/use qualification.

The full local gate passes **1675 tests / 34503 assertions**, exact **6218/6218 owned statements**; focused checks pass
**302 tests / 11891 assertions**. No final warnings/skips or dependency drift. TASK-00058 owns logs, input manifest,
receipt and failure chronology. At this implementation checkpoint M4 remained unchecked pending independent review;
the guide specifies mandatory unexecuted real-consumer rehearsal and abort/resume evidence. M5/TASK-00059 final
integration remains outstanding. No publication, merge, release, actual restore, consumer adoption or Agent OS
TASK-00138 closure was claimed at that checkpoint.

Independent review subsequently accepted TASK-00058 at `eeb6f5f` against unchanged `develop` `80e9add`, with C1–C8
and all applicable Spec/Standards IDs passing, no findings, **1095 fresh tests / 30016 assertions**, and all
**782/782** final build inputs verified. M4 is accepted at the package boundary; TASK-00058 is done for implementation
and local verification. John requested landing; the ignored TASK landing handoff owns fresh publication gates and
final local/remote identities. This intake precedes publication. Hosted CI remains optional/unchecked; interactive
QA is N/A. Actual consumer rehearsal, final integration, merge, release and adoption are not established.

## Acceptance Evidence

- [ ] **M1 — Remove previous-iteration support:** TASK-00068 removes legacy-Agent mode/adoption, marker/read/schema
      fields, retired API stubs and compatibility-only paths/tests/guidance. Current lifecycle and hydration safety
      pass without a legacy distinction. TASK-00055's old M1 acceptance is historical, not acceptance of this removal.
- [x] **M2 — Contract cohorts:** test missing capability/contract storage and incompatible/restarted consumers deny
      mutation/admission without fallback. Document all lifecycle and authorization writers plus the necessary
      storage/trusted-boundary fencing of old binaries. Published conformance obligations include real writer races;
      do not claim a new-code version check fences an old binary by itself.
- [x] **M3 — One canonical contract:** keep the best Unicode-aware normalization as the sole supported rule and
      marker; remove old readers, multi-version creation/reader selection and compatibility-only APIs/tests/guidance.
      Actual services preserve same-contract retry/restart and retained/tombstoned outcomes, reject changed bindings
      and unsupported/corrupt state without fallback, and retain current authority, atomicity and destination/order
      fences. No backwards compatibility or migration of nonexistent old data is required. TASK-00057 C1–C8 own proof.
- [x] **M4 — Restoration safety:** observable package/conformance tests demonstrate that stale restored state cannot
      silently authorize duplicate issuance, reset order or resurrect retired keys after external acceptance. Document
      current-contract reconciliation prerequisites, forward repair and unavailable outcomes. Real current-composition
      and same-contract backup/restore rehearsals are consumer evidence; no cross-version rollout/migration is required.
- [ ] **M5 — Complete evidence and guidance:** maintain the matrix above with actual test/receipt references as work
      completes; every scenario has an owner, result and explicit gap where proof is missing. TICKET-00012/00013
      retain their own behavior tests and public consumer-bindable suites. Guidance covers D1–D4, current integration/deployment
      order, secret-safe outcomes, bounded defaults and supported consumer obligations without claiming implementation,
      publication or adoption from planning. No real-adapter qualification is inferred from a mock or in-memory fake.
- [ ] Current-contract tests assert outcomes, not file/configuration text. Consumer-owned deployment/restore tools
      provide direct current-runtime proof before consumer qualification; no prior-version migration proof is required.
      The full package `./bin/build`, including exact statement coverage, and `./bin/planning-check` pass for
      implementation; current documentation links pass.
      Preserve warnings, failures and incomplete evidence rather than changing the acceptance scope.

## Evidence Boundaries and Sequencing

TICKET-00012 owns issuance/resolution tests and persistence/authorization conformance; TICKET-00013 owns delivery,
sink and lifecycle/admission tests/conformance. This TICKET depends on both public contracts for current safety
and current integration guidance; draft those requirements alongside their design. Prior-version support is excluded.
There is no permissible unsafe intermediate release of one part of the protocol.

Package acceptance needs executable owned behavior tests and reusable consumer-bindable conformance contracts with
traceable coverage. Supporting any actual consumer additionally requires real persistence, authority-writer and sink
runs proving atomicity, uniqueness, generations, receipt order and activation/use authority. PostgreSQL behavior is
not proven by in-memory fixtures. Consumer-owned schema/deployment/restore rehearsals are separately executed in that
repository; this record defines their required evidence and handoff, not permission to perform them. Do not mark
consumer qualification complete merely because package criteria pass. Agent OS adoption, other R1 prerequisites and
R2/R3 evidence remain separate, and its TASK-00138 stays unchanged.

## Exclusions

No production Adapter layer, concrete schema/ORM/SQL or provider selection, consumer migration execution, Agent OS
Composer/vendor change, enrollment/broker implementation, HTTP contract, generic migration framework, tag/release/
publication, deployment or TASK-00138 closure. No extra routine human approval, unrestricted secret retrieval,
legacy raw-return escape path or automatic credential backfill. New generic security Permissions and business events
are N/A: this TICKET specifies compatibility and consumer qualification, not a new authorization policy or workflow.

## TASK Ownership and Readiness

John approved this TICKET's five-TASK split on 2026-09-27. Each slice normally owns one independently reviewable PR;
compatibility behavior tests stay with their owner rather than moving into final documentation. These parented
features/guidance records do not use the standalone bug/chore `kind` exception. The later package-wide TASK-00068
is a standalone chore spanning more than this TICKET; it supplies revised M1 and is explicitly linked below.

| Slice | TASK | Blockers | Acceptance ownership |
| --- | --- | --- | --- |
| A — Existing-Agent compatibility (superseded) | [TASK-00055](../tasks/00055-TASK.md) | TASK-00047, TASK-00048 | Historical acceptance only; ADR 0011 replaces this obligation with TASK-00068 removal. |
| Package cleanup | [TASK-00068](../tasks/00068-TASK.md) | — | Revised M1: remove previous-iteration support, including legacy mode/API/schema/test paths. |
| B — Contract cohorts | [TASK-00056](../tasks/00056-TASK.md) | TASK-00048, TASK-00052, TASK-00053 | M2: persisted compatibility/capability guards across actual writers, plus explicit external old-binary fencing obligations. |
| C — One canonical contract (amended) | [TASK-00057](../tasks/00057-TASK.md) | TASK-00056, TASK-00047 | M3: best current normalization without historical compatibility; retained-key/restart safety through actual public services. |
| D — Restoration safety | [TASK-00058](../tasks/00058-TASK.md) | TASK-00056, TASK-00057, TASK-00068 | M4: current-contract restored-state guards/conformance, not historical-format support or automatic rollback detection. |
| E — Current guidance and traceability | [TASK-00059](../tasks/00059-TASK.md) | TASK-00055, TASK-00056, TASK-00057, TASK-00058, TASK-00049, TASK-00054, TASK-00068 | M5: current integration guide; all 25 proposal scenarios and D3/D4 additions accounted for, with superseded requirements labeled and real results/gaps. |

These are downstream dependencies on real issuance/delivery and compatibility paths; no predecessor is made dependent
on this TICKET's final evidence aggregation. TASK-00049/00054 retain issuance/delivery conformance ownership. The
scenario matrix above remains the complete requirement map; TASK-00059 adds verified results as implementation
completes, not invented passes during planning.

TASK-00056/00058 must distinguish package checks from consumer-controlled storage/admission fences and externally
reconciled evidence. A new-code guard cannot stop unchecked old binaries or detect arbitrary database rollback;
unsupported integration remains unsupported. Consumer migrations, real writer/sink tests, activation/use authority
proof and rollout/restore rehearsals stay separate adoption obligations, not permission granted by this plan.

## Progress

John authorized TASK-00055 in the main checkout from `develop` `ea1e316`, after TASK-00047/00048 completed. It is now
done for independently accepted implementation/local verification on `feature/task-00055-legacy-compatibility`. The
[existing-data/API contract](../../docs/agent-existing-data-v0.5-migration.md) and executable M1 scenarios cover explicit
legacy reconstitution, preserved authentication, safe marker reads and new authorized rotation through the existing
transaction. No historical operation/delivery is manufactured. Its local gate passes 1318 tests / 18169 assertions and
exact 6270/6270 statements; focused Agent/OpenAPI checks pass 797 tests / 14246 assertions with exception arguments
captured. Independent review accepted `ebc396c` with all seven criteria passing, no findings and all 736 gate/bridge
inputs verified; M1 is accepted for package behavior. John subsequently requested PR landing; the TASK's ignored
handoff owns publication/remote verification and the administrative-only provenance bridge. Consumer schema migration,
real adapter/concurrency proof and adoption are not claimed. TASK-00056's accepted M2 checkpoint is recorded above;
TASK-00057's original accepted M3 checkpoint is historical above; the 2026-09-30 amendment was independently
accepted at `2de4104` after simplification. M4–M5 retain their separate downstream owners and unfinished dependencies.
The Board owns current executable order; the following inventory evidence describes the original planning checkpoint.

Before allocation, refreshed local live/archive inventory at unchanged HEAD
`92d1de82a7833cc6dafb90eccea0d132f0e3cd77`: live TASK IDs ended at 00054, archive held only its README and no duplicate
Agent compatibility/migration TASK was found. Orders 55–59 preserve the existing portfolio. No remote inventory was
queried; prior uncommitted work and unrelated TASK-00035 remain preserved.

EPIC-00009's three TICKETs now all have approved TASK decompositions. This completes planning, not implementation,
release or adoption. Implementation requires separate authority and explicit branch/worktree selection. At the
planning-only checkpoint before publication authorization, no product build, runtime/consumer qualification,
migration/restore execution, commit, publication, dependency upgrade or Agent OS TASK-00138 closure was claimed. Planning verification is recorded in the parent EPIC after regeneration.

[proposal]: https://github.com/johnnickell/fight-agent-os/blob/ecd849ec8817b460dbaf170df84458c56c40a0d5/docs/engineering/ACCESS_CONTROL_AGENT_OPERATIONS_PROPOSAL.md#required-behavior-evidence-before-package-acceptance

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 55 | [TASK-00055](../tasks/00055-TASK.md) | Preserve existing Agent authority through upgrade | done |
| 56 | [TASK-00056](../tasks/00056-TASK.md) | Reject incompatible credential-operation cohorts | done |
| 57 | [TASK-00057](../tasks/00057-TASK.md) | Simplify Agent operations to one canonical contract | done |
| 58 | [TASK-00058](../tasks/00058-TASK.md) | Fail closed on unreconciled credential-state restoration | done |
| 59 | [TASK-00059](../tasks/00059-TASK.md) | Complete current-contract guidance and evidence traceability | ready-for-agent |
