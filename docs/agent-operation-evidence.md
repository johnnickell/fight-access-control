# Agent operation scenario and evidence inventory

This is the current-contract M5 inventory for [TASK-00059](../planning/tasks/00059-TASK.md), supporting the
[integration guide](agent-integration.md). It does not implement deferred behavior, independently accept this TASK,
release v0.5.0, qualify a consumer or close Agent OS TASK-00138.

## Scope, provenance and result vocabulary

Rows 01–25 preserve the order and every scenario in the pinned proposal's
[required behavior matrix at `ecd849e`][proposal], also owned by
[TICKET-00014](../planning/tickets/00014-TICKET.md#scenario-ownership-and-evidence-traceability).
I1–I7 belong to [TICKET-00012](../planning/tickets/00012-TICKET.md), D1–D7 to
[TICKET-00013](../planning/tickets/00013-TICKET.md), M1–M5 to TICKET-00014. The **EPIC's** ratified D3/D4 decisions
are separately mapped below; they are not the delivery TICKET's same-numbered criteria.

- **P — package pass:** cited assertions executed successfully in the current focused run F1 below. Services are real
  package code, but persistence, authority, time, key and sink collaborators are controlled behavioral adapters.
  A pass is not a real database/process/cryptography test or independent TASK-00059 acceptance.
- **S — superseded:** ADR 0011 replaces previous-iteration requirements. Old-format/legacy migration is neither a
  missing test nor a fabricated pass. Current safety remaining in those rows still requires P evidence.
- **G1–G6 — consumer gaps:** mandatory actual-adapter/tool/activation evidence **not executed here**. These prevent a
  consumer support/adoption claim, not documentation of already tested package behavior.

**F1 (fresh, 2026-09-30):** at source base `37a98f32b0cf502f90817cf094705c4ce1c41983`, with only documentation/planning
changes, the isolated `fight-access-control` PHP 8.5.7 runtime ran Domain Agent, Application Agent and all OpenAPI
suites: **1103 tests / 30421 assertions, exit 0**, no warnings/skips. Command inside the container:

```sh
php -d zend.exception_ignore_args=0 vendor/bin/phpunit --fail-on-skipped --no-coverage --testdox \
  tests/Domain/AccessControl/Agent tests/Application/AccessControl/Agent tests/OpenApi
```

Saved receipt/log/input hashes: `.runs/logs/TASK-00059/focused-agent-2.{receipt.json,log,exit,inputs.json}`.
The owning TASK retains these local review artifacts; ignored paths are not public downloadable evidence. Initial F1
attempt with optional `--log-junit` failed six discovery-test exception-serialization cases: captured PHPUnit runner
arguments reached its nonserializable `DOMDocument`. Removing that optional reporter, **not** exception-argument
capture or assertions, yielded the pass above. The failed log/exit remain `focused-agent.*`; this is a disclosed
reporter-combination limitation, not a passing XML run or a production repair.

**B0 (verified earlier complete gate):** TASK-00058 landing at `dbe1d650afc4a1d1212998113fa0919b78277f8a` ran the
unmodified commit hook's `./bin/build`, 2026-09-30 08:57:30–08:58:51 UTC, exit 0: **1675 tests / 34503 assertions**,
exact **6218/6218 owned statements**, all quality/link/planning stages passed. Its 782-entry manifest (781 tracked
files plus local lockfile) was compared with that commit and current inputs; product/test/schema/dependency/build
inputs match. Intervening changes are planning/documentation only. This establishes current content provenance,
not merely an old green count. Log SHA-256: `0efa2b1709e32f047d2deeddfd081a33e30cfe5a3fcda9aef3cb5b69cfd607e2`;
artifacts `.runs/logs/TASK-00058-landing/metadata-commit.{log,exit,inputs.json}` and
`.runs/logs/TASK-00059/upstream-input-verification.json`. See TASK-00059 for its final checks/commit-hook receipt;
B0 remains explicitly historical, not a claim it ran again at a later head. Coverage is the combined configured
owned-statement gate, not a separately measured unit-only metric or proof of real concurrency.

## Executable suite keys and ownership

Method references in the matrix resolve through this table. F1 ran all these current suites, not the superseded
versions originally reviewed. The linked slice guides retain finer-grained predecessor tests and binding obligations.

| Key | Current source / runners | Owning TASK and evidence map |
| --- | --- | --- |
| I | [IssuanceRecoveryConformance](../tests/Application/AccessControl/Agent/Security/IssuanceRecoveryConformance.php), `InMemoryIssuanceRecoveryConformanceTest` | [TASK-00049](../planning/tasks/00049-TASK.md); [I1–I7 map](agent-issuance-conformance.md#evidence-map) |
| L | [DeliveryLifecycleConformance](../tests/Application/AccessControl/Agent/Security/DeliveryLifecycleConformance.php), `InMemoryDeliveryLifecycleConformanceTest` and `InMemoryDeliveryWithoutLookupConformanceTest` | [TASK-00054](../planning/tasks/00054-TASK.md); [D1–D7 map](agent-delivery-conformance.md#evidence-map-d1d7) |
| SINK | [ProtectedSinkConformance](../tests/Application/AccessControl/Agent/Security/ProtectedSinkConformance.php), `InMemoryProtectedSinkConformanceTest` | TASK-00054; same D1–D7 map |
| C | [AgentCohortConformance](../tests/Application/AccessControl/Agent/Security/AgentCohortConformance.php), `InMemoryAgentCohortConformanceTest`; [AgentCohortWriterTest](../tests/Application/AccessControl/Agent/Security/AgentCohortWriterTest.php) | [TASK-00056](../planning/tasks/00056-TASK.md); [M2 map](agent-operation-cohorts.md#executable-evidence-and-consumer-obligations), current amendments in TASK-00057/00058/00068 |
| K | [AgentCanonicalConformance](../tests/Application/AccessControl/Agent/Security/AgentCanonicalConformance.php), `InMemoryAgentCanonicalConformanceTest` | [TASK-00057](../planning/tasks/00057-TASK.md); [M3 map](agent-canonical-upgrades.md#m3-executable-evidence) |
| R | [AgentRestorationConformance](../tests/Application/AccessControl/Agent/Security/AgentRestorationConformance.php), `InMemoryAgentRestorationConformanceTest` and `InMemoryAgentRestorationWithoutLookupTest`; [AgentRestorationWriterTest](../tests/Application/AccessControl/Agent/Security/AgentRestorationWriterTest.php) | [TASK-00058](../planning/tasks/00058-TASK.md); [M4 map](agent-restoration-safety.md#m4-executable-evidence) |
| P / ROT | [AgentProvisioningServiceTest](../tests/Application/AccessControl/Agent/Security/AgentProvisioningServiceTest.php) / [AgentCredentialRotationServiceTest](../tests/Application/AccessControl/Agent/Security/AgentCredentialRotationServiceTest.php) | [TASK-00046](../planning/tasks/00046-TASK.md) / [TASK-00048](../planning/tasks/00048-TASK.md); [provision](agent-provisioning-operations.md#package-evidence-and-consumer-qualification) / [rotation](agent-rotation-operations.md#evidence-and-limits) maps |
| STATUS / RET | [GetAgentOperationHandlerTest](../tests/Application/AccessControl/Agent/QueryHandler/GetAgentOperationHandlerTest.php) / [AgentCredentialRetirementTest](../tests/Application/AccessControl/Agent/Security/AgentCredentialRetirementTest.php) | [TASK-00047](../planning/tasks/00047-TASK.md) / [TASK-00050](../planning/tasks/00050-TASK.md); [status](agent-operation-status.md#executable-evidence-and-limits) / [retirement](agent-credential-retirement.md#package-evidence-and-remaining-qualification) maps |
| DEL / REC / MAINT | [AgentCredentialDeliveryServiceTest](../tests/Application/AccessControl/Agent/Security/AgentCredentialDeliveryServiceTest.php) / [AgentDeliveryRecoveryServiceTest](../tests/Application/AccessControl/Agent/Security/AgentDeliveryRecoveryServiceTest.php) / [AgentDeliveryMaintenanceServiceTest](../tests/Application/AccessControl/Agent/Security/AgentDeliveryMaintenanceServiceTest.php) | [TASK-00051](../planning/tasks/00051-TASK.md) / [TASK-00052](../planning/tasks/00052-TASK.md) / [TASK-00053](../planning/tasks/00053-TASK.md); [delivery](agent-credential-delivery.md#evidence-and-limits), [recovery](agent-delivery-recovery.md#api-and-verification-boundaries), [maintenance](agent-delivery-maintenance.md#adapter-obligations-and-acceptance-evidence) maps |

Reference `contend()` schedules are serialized; predecessor P/ROT tests inject actual loser rollback/CAS and winner
visibility, not parallel database sessions. Delivery/sink/cohort/restore suites use deterministic barriers over
modeled persisted state. `restart()` reconstructs services, not an OS kill. Direct repository rotation tests prove
cancellation, not complete successor issuance/audit. Safe read fixtures prove projection only; integrated I/L cases
supply actual package writers. R's separate retained journal/sink model is not a production restore algorithm.

### Historical acceptance provenance

Owning TASKs retain full original logs/manifests and canonical reviews under their ignored runs. Independently accepted
candidates were: TASK-00049 `3fcf2c5`, TASK-00054 `7b58fbe`, TASK-00056 `ff5c035`, **amended** TASK-00057 `2de4104`,
TASK-00068 `90c22b5`, TASK-00058 `eeb6f5f`. These explain ownership, not acceptance of every later source revision.
F1/B0 establish the tested current content; no old test count is relabeled as a current run. TASK-00055's legacy
acceptance and TASK-00057's original `04a2adf` cross-version acceptance are historical only. TASK-00068's
[removal/retention accounting](../planning/tasks/00068-TASK.md) and ADR 0011 supersede those obligations.

## Complete proposal matrix

Every P below refers to F1 and the exact methods/classes listed. Shared rows include both issuance and delivery
owners rather than substituting one side's pass. Consumer gaps apply even when no package assertion is missing.

| # / pinned scenario | Owner and executable references | Observed package result / remaining evidence |
| --- | --- | --- |
| 01 Normal provision and rotation | I1 / TASK-00046/00048/00049: P `test_it_commits_one_complete_issuance_before_publication_and_returns_no_secret`, ROT `test_rotation_commits_before_publication_and_recovers_after_restart`; D1/D3 / TASK-00054: L `test_restart_resolves_each_uncertain_delivery_commit_without_reissuing` | P: one issuance/audit, original safe identity; separate receipt-confirmed delivery removes only its copy. G1/G3/G4. |
| 02 Commit succeeds, response is lost, then caller/service restarts | I2 / TASK-00049: I `test_same_key_retry_preserves_fact_identity_and_current_authority`; P `test_lost_response_and_service_restart_resolve_without_generation_audit_or_another_fact`; ROT `test_rotation_commits_before_publication_and_recovers_after_restart`; K `test_restart_preserves_original_keys_in_every_retained_state` | P: exact original outcome after reconstructed services, including original rotation predecessor, with no repeated generation/audit/fact. G1/G2 real process loss. |
| 03 Indeterminate issuance commit, for both provision and rotation | I3 / TASK-00049: I `test_indeterminate_commit_resolves_original_request_after_restart`; P/ROT uncertain-commit cases | P: both committed/rolled-back storage outcomes return indeterminate first; same original key resolves, never partial persisted issuance. G1 actual connection/ack loss. |
| 04 Indeterminate admission or delivery-outcome commit | D3 / TASK-00052/00054: L `test_restart_resolves_each_uncertain_delivery_commit_without_reissuing`; REC uncertainty/receipt-only cases | P: both issuance kinds, three commit stages and both storage outcomes; no materialization from uncertain admission, original receipt recovery or recorded completion. Lookup and non-lookup profiles. G1/G3. |
| 05 Success event publication throws after commit, for both provision and rotation | I4 / TASK-00046/00048/00049: P `test_publication_failure_preserves_committed_work_and_returns_only_a_typed_warning`, ROT `test_both_publisher_failures_never_disguise_committed_issuance`; I combined recovery case | P: confirmed metadata with typed warning, even both publishers failing; retry retains single audit. No notification-retry implementation claimed. G4/G6. |
| 06 Both publishers fail and caller never retries | I4 + D1 / TASK-00049/00052/00054: I `test_both_publishers_and_caller_can_disappear_before_scheduler_only_recovery`; L `test_default_restart_skips_an_entire_obsolete_batch_without_manual_cleanup` | P: provision/rotation × pending/abandoned claim; caller inputs/events discarded, delegated scheduler delivers once confirmed, next pass empty, original audit/bytes retained. Modeled termination, not OS kill. G1/G2/G3/G4. |
| 07 Pre-commit audit, encryption or persistence failure | I3 / TASK-00046/00048/00049: P `test_precommit_faults_roll_back_every_record_and_never_expose_provider_details`; ROT `test_precommit_failures_roll_back_successor_retirement_reservation_and_audit`; I `test_precommit_failure_rolls_back_every_participant_and_reservation` | P: all records, reservations and predecessor cancellation roll back; generation attempts are not persisted success. G1/G3 actual transaction and encryption faults. |
| 08 Same key, changed name/target/predecessor/kind/destination | I2 / TASK-00049/00057: I `test_retained_binding_conflicts_and_unknown_versions_never_issue`; K `test_changed_bindings_conflict_after_restart`, `test_new_keys_share_one_normalized_name_contract_but_not_issuance_or_slot_order` | P: changed bindings conflict without issuance/disclosure; equivalent Unicode-edge names separately resolve. G1. |
| 09 Wrong caller/namespace, revoked authorization, expired delegation | I5 + D1/D2 / TASK-00049/00052/00054/00057: I `test_untrusted_scope_and_destination_cannot_read_or_resolve_an_existing_key`, `test_discovery_requires_delegation_and_cannot_cache_admission_authority`; K `test_wrong_scope_caller_and_expired_delegation_deny_lookup`; L authority/deadline cases; STATUS authority cases | P: current checks on new/retry/status/discovery; distinct delegated worker does not rewrite scope, and discovery cannot authorize admission. G2/G4. |
| 10 Equal opaque operation IDs across scopes at one sink | I5 + D4 / TASK-00049/00054: I `test_equal_ids_in_different_scopes_share_monotonic_slot_order_not_issuance`; SINK `test_equal_operation_ids_and_reassigned_slots_keep_global_order_and_exact_receipts` | P: distinct Agent/delivery IDs, denied obsolete ownership, monotonic cross-scope binding order even at equal claim fences. G1/G2/G3/G4. |
| 11 Caller/delegation/destination authority changes around admission | D2 / TASK-00051/00052/00054: L `test_authority_changes_deny_early_or_leave_only_inert_late_bytes`, `test_fenced_authority_writer_and_aba_require_a_new_admission`, `test_earliest_authority_deadline_guards_materialization_invocation_and_completion`; REC receipt-reconciliation authority case | P: early denial without decryption, late inert bytes without stale acknowledgement, deadline and ABA fencing. Real activation/use remains untested. G2/G3/G4. |
| 12 Authorization and lifecycle writers share the transaction fence | I5 + D2/D5 / TASK-00049/00050/00054/00056: I `test_authority_writer_and_issuance_have_one_fenced_order`, `test_outer_transaction_cannot_wrap_an_issuance_service`; L fenced-writer and all-path retirement cases; C `test_cohort_switch_contends_with_live_transaction_not_just_startup_check`; RET rollback/participation cases | P for modeled ordering/rollback/denial only. The pinned real-consumer obligation is **not established**: G1/G2 covers every actual writer and independent connection, including non-HTTP paths. |
| 13 Concurrent identical and conflicting requests | I2 / TASK-00046/00048/00049: I `test_contending_same_key_requests_resolve_one_issuance`, `test_contending_different_bindings_leave_one_winner_and_one_conflict`, `test_competing_rotation_keys_cannot_both_replace_one_predecessor`; P `test_unique_loser_rolls_back_then_resolves_or_conflicts_with_winner`, ROT `test_concurrent_loser_rolls_back_then_resolves_only_the_authoritative_winner` | P: one scoped-key winner and at most one successor in modeled contention; no claim of physical concurrency. G1/G2. |
| 14 Store unavailable; store accepts then response is lost; delivery outcome commit fails | D3 / TASK-00051/00052/00054: L `test_each_failed_persistence_stage_rolls_back_and_scheduler_recovers`, `test_lost_response_takeover_and_delivered_secret_loss_never_trigger_secret_reread`; I `test_lost_sink_response_recovers_original_bytes_and_id_not_issuance`; DEL transient sink cases | P: original ID/bytes survive, no delivered result without receipt, no fallback issuance. G1/G3 real store/transport failure. |
| 15 Concurrent delivery retry and lease takeover | D3 / TASK-00051/00052/00054: L `test_lost_response_takeover_and_delivered_secret_loss_never_trigger_secret_reread`; DEL `test_competing_claim_and_takeover_cannot_acknowledge_an_old_admission` | P: takeover advances fence, original bytes/ID remain, stale claimant rejects after fresh recovery. G1/G2/G3. |
| 16 Different operations deliver out of order | D4 / TASK-00048/00054: SINK `test_actual_rotation_orders_late_predecessors_across_slots_without_selecting_old_authority`, `test_equal_operation_ids_and_reassigned_slots_keep_global_order_and_exact_receipts`; ROT `test_successor_delivery_wins_over_an_older_in_flight_invocation` | P: same/cross-slot and both arrival orders; cross-Agent/scope reassignment retains exact order. Inertness is the consumer use contract, not a tested broker. G3/G4. |
| 17 Receipt or idempotency binding mismatch | D4 / TASK-00051/00054: SINK `test_every_immutable_receipt_or_idempotency_field_and_bytes_reject_mismatch`; `AgentProtectedSinkContractTest` missing/swapped/equal-order cases | P: all twelve tuple fields plus bytes reject changed binding, original receipt/order retained; missing or unverifiable receipt cannot confirm delivery. G3/G4. |
| 18 Revocation/supersession before and during invocation | D2/D5 / TASK-00048/00050/00054: L `test_every_retained_lifecycle_writer_fences_real_delivery_and_original_status`; I `test_actual_delivery_then_revocation_retains_original_status_without_authority`; `AgentRotationAuthenticationTest` | P: retired original cannot acknowledge or authenticate a fresh request; status keeps original identity, not successor material. G1/G2/G3/G4. |
| 19 Delivered-secret loss, retention expiry and replay after tombstoning | D6 + I6 / TASK-00049/00053/00054: I `test_terminal_delivery_retains_key_without_raw_or_envelope_fallback`, `test_expiry_and_cleanup_never_make_original_key_fresh_issuance`; L lost-response/loss and `test_retention_cleanup_and_replay_preserve_correlation_and_slot_order`; MAINT cleanup cases | P: no material reread/revival/reissue; cleanup retains key/order/tombstone and rejects delayed staging. Lost delivered entry returns reconciliation-required while historical delivery remains. G1/G3/G4/G5. |
| 20 Decryption/key access fails after issuance | D6 / TASK-00051/00053/00054: L `test_rewrap_key_failure_and_restart_preserve_original_binding_without_envelope_fallback`, `test_rewrap_during_delivery_fences_stale_completion_and_retains_retry_history`; DEL temporary/permanent/corrupt cases; MAINT key-reference/closure cases | P: temporary recovery keeps original bytes/ID; permanent/corrupt/swapped material terminalizes, no envelope fallback; rewrap preserves binding/history. G3/G5. |
| 21 Every retained lifecycle entry point races delivery | D5 + I7 / TASK-00048/00050/00054/00068: L all-path retirement and `test_revocation_publication_failure_remains_a_throw_after_durable_retirement`; RET direct/service cancellation and trace safety | P: four current service/direct paths × five boundaries, atomic cancellation; direct cancellation is not full successor issuance. **S:** old-signature rejection stubs replaced by removal under ADR 0011/TASK-00068. G1/G2/G4. |
| 22 Existing-Agent upgrade and incompatible consumers | M1/M2 / TASK-00068/00056/00058: current `AgentReconstitutionTest`, `AgentHydrationTest`, `AgentComponentsTest`; C `test_incompatible_restarted_workers_do_not_mutate_or_materialize`, `AgentCohortWriterTest` | **S:** legacy preservation/adoption/migration from TASK-00055. P: validated current hydration and unconditional correlation; missing/incompatible composition and unreconciled writers deny effects. G1/G2/G5; no legacy migration evidence required. |
| 23 Canonical-request versions across upgrade/restart | M3 + I6 / TASK-00057/00049/00068: K `test_restart_preserves_original_keys_in_every_retained_state`, `test_unsupported_markers_and_corrupt_bindings_never_fall_back_or_disclose`; `AgentOperationCanonicalizationTest`, `AgentOperationContractTest` | **S:** old-reader/version-selection/cross-version upgrade proof. P: both operations × six retained states preserve current keys; sole marker 2, exact equality/conflict and unsupported-marker denial. G1/G5 current-state restart only. |
| 24 Rollback/restore after external acceptance | M4 + D4/D6 / TASK-00058/00054/00053: R `test_stale_package_state_never_reopens_unsafe_public_paths`, `test_verified_repair_preserves_keys_order_terminal_state_and_identity`, `test_incomplete_or_mismatched_external_evidence_cannot_enable_repaired_state`, `test_reconciled_cleanup_retains_the_sink_tombstone_against_delayed_replay`; `AgentRestorationWriterTest`; L cleanup/replay; SINK order/binding cases | P: nine guarded public paths × five histories, independent modeled sink/journal, missing/exact-binding/order/tombstone evidence, stale acknowledgements and retained replay defenses. **Not** physical restore or trusted-evidence authentication: G1/G2/G3/G4/G5. |
| 25 Safe representations and sensitive-value lifetime | I7 + D6 / TASK-00049/00050/00051/00054/00068: I/L `assertSafe()` on actual results/events/audit/failures; DEL `test_invocation_and_results_do_not_serialize_or_debug_secret_material`; RET `test_retirement_failure_debug_redacts_agents_with_exception_arguments_enabled`; Domain material/invocation and current View/OpenAPI tests | P: serialization/debug/failure safety with exception arguments enabled; sensitive fixed invocation, no safe-view secret getter. PHP memory erasure and real logging/crypto are not proven. G3/G6; F1 reporter limitation remains disclosed. |

## Ratified additions

| EPIC decision / owners | Executable evidence and observed result | Remaining evidence |
| --- | --- | --- |
| D3 outcome separation; I1/I3/I4/I7 + D1/D2/D4, TASK-00046/00048/00049/00054 | P: rows 01–07, 11, 16, 18, 25; I's both-publisher/no-caller case observes pending before delivery and exact confirmed receipt afterward; precommit/uncertainty cases keep warnings only on confirmed issuance. L revocation-publication case proves exception scope does not expand. Authentication after revocation denies despite retained receipt. No outbox/notification retry is asserted. | G4 actual enrollment activation and every use require their own current-authority confirmed outcomes; package results cannot establish them. G6 real publication/logging safety. |
| D4 finite defaults/overrides, capacity-preserved existing recovery and cleanup; I6 + D7, TASK-00046/00049/00051/00052/00053/00054 | P: I `test_capacity_preserves_status_resolution_and_bounded_recovery`; L `test_invalid_overrides_reject_without_work`, `test_default_restart_skips_an_entire_obsolete_batch_without_manual_cleanup`, `test_valid_overrides_pin_retry_retention_and_cleanup_without_manual_approval`, `test_capacity_policy_and_storage_outage_do_not_disguise_existing_recovery`; rows 19–20. Domain `AgentOperationTest`, `AgentDeliveryTest`, `AgentDeliveryDiscoveryTest`, `AgentMaintenanceTest` and `AgentMaintenanceQueryTest` cover exact bounds, keyset pages and global counts. Values are in the integration guide, not new speculative defaults. | G1 real capacity/uniqueness/rollback; G3 actual key closure/reference/cleanup races; G5 retention/replay through restore. Routine recovery needs no extra approval; an outage still cannot be reported as success. |

## Consumer gap register

These gaps are mandatory **unexecuted consumer evidence**, not hidden package passes or new package feature scope.
The consumer maintainer owns every row. Do not enable an unsupported integration merely because M5 is documented.

| Gap | Required real evidence and owning guidance |
| --- | --- |
| G1 — Persistence and process behavior | Shared-connection transactions, atomic scoped/global uniqueness, predecessor CAS, capacity and reservation rollback, both uncertain-commit outcomes; independent concurrent sessions/processes and real restart/termination. Record isolation, connection identities, barriers, faults and independently observed persisted results. [Issuance binding](agent-issuance-conformance.md#fixture-obligations), [delivery binding](agent-delivery-conformance.md#fixture-semantics-and-real-fault-injection). |
| G2 — Authority/writer exclusion | Inventory **every** HTTP/CLI/worker/direct/admin policy, lifecycle, destination, key and cohort writer. Race both winning orders, revoke/regrant ABA and expiry; deny excluded binaries/connections after restart; substitute actual incompatible participants. A capability array/startup flag/new-code-only guard is insufficient. [Cohort obligations](agent-operation-cohorts.md#executable-evidence-and-consumer-obligations). |
| G3 — Cryptographic key and sink behavior | Actual authenticated associated data for all tuple fields, source/target failures, closed key-version reference admission, in-flight key accounting, immutable receipt/idempotency/order/cleanup under concurrency, changed bytes and delayed replay. Prove sink calls outside transactions and bounded rewrap inside its fence. [Delivery](agent-credential-delivery.md#protected-sink-and-cipher-obligations), [maintenance](agent-delivery-maintenance.md#adapter-obligations-and-acceptance-evidence). |
| G4 — Activation and use | Real activation transaction, lost activation acknowledgement, fresh broker/use authority, exact current destination/credential selection before the sink learns replacement, denial of late/obsolete/revoked bytes and permissions. Preserve separate activation and actual use-effect observations. [Mandatory scenarios](agent-delivery-conformance.md#consumer-activationuse-binding--mandatory-not-executed-here). |
| G5 — Trusted admission and actual restore | Consumer-owned tool/version/command receipts for stale current-contract physical restore, independent incarnation/witness/history authentication, missing/swapped/replayed evidence, uncertain witness publication, writer/admitted-call races and abort/forward-repair/resume. No old-format migration or rollback detector is supplied. [Mandatory rehearsals](agent-restoration-safety.md#mandatory-external-qualification--not-executed-here). |
| G6 — Real sensitive surfaces | Actual logging, tracing, provider exceptions and callback/repository parameter redaction with arguments enabled; no raw/ciphertext/receipt capability leak or retained invocation. Sanitized observations only, not exported secrets as proof. [Trace contract](agent-credential-retirement.md#composition-and-atomic-cancellation). |

A consumer handoff must attach its exact package/source/dependency and adapter identities, runtime/key/sink/tool
versions, commands and start/end/exit receipts, writer/connection inventory, fault schedules and protected independent
observations for each applicable row and both receipt-lookup profiles it supports. Do not skip an unsupported required
capability, replace assertions with canned outcomes or declare a fake adapter run to be PostgreSQL proof. Missing
real evidence remains an adoption blocker. Separately authorized release certification, dependency adoption and
deployment follow their own gates; neither this inventory nor package coverage grants them.

[proposal]: https://github.com/johnnickell/fight-agent-os/blob/ecd849ec8817b460dbaf170df84458c56c40a0d5/docs/engineering/ACCESS_CONTROL_AGENT_OPERATIONS_PROPOSAL.md#required-behavior-evidence-before-package-acceptance
