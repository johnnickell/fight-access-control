# Agent protected-delivery and lifecycle conformance (unreleased v0.5.0)

[TASK-00054](../planning/tasks/00054-TASK.md) adds reusable **product test contracts**, not production adapters,
endpoints, new Commands/Queries/Events or an alternate worker. The suites compose real provision, rotation,
revocation, delivery, discovery, maintenance and original-operation status. Package-controlled bindings demonstrate
persisted outcomes under deterministic interleavings. They do **not** qualify a real database, key service, sink,
enrollment activation or broker use. The intermediate protocol remains unreleased and unsupported for deployment.

## Run and bind

The reusable suites are:

- [DeliveryLifecycleConformance](../tests/Application/AccessControl/Agent/Security/DeliveryLifecycleConformance.php):
  authority, rollback/commit uncertainty, restart/takeover, all-path retirement, keys, maintenance and bounds.
- [ProtectedSinkConformance](../tests/Application/AccessControl/Agent/Security/ProtectedSinkConformance.php): exact
  receipt/idempotency binding, repeated bytes, real same/cross-slot rotation and cross-Agent/scope reassignment.

They share [DeliveryConformance](../tests/Application/AccessControl/Agent/Security/DeliveryConformance.php), which
constructs public package services. Implement only `newFixture(): DeliveryConformanceFixture` in each concrete
subclass. The [fixture contract](../tests/Application/AccessControl/Agent/Service/Conformance/DeliveryConformanceFixture.php)
defines adapter setup, fault injection and independent observations, not lifecycle decisions. It shares the public-port
bundle `IssuanceRecoveryPorts` with issuance conformance, not its workflow or implementation fixture. Neither
conformance slice is a prerequisite for the other's production inputs.

Reference runners are `InMemoryDeliveryLifecycleConformanceTest`, `InMemoryDeliveryWithoutLookupConformanceTest`
and `InMemoryProtectedSinkConformanceTest`. They run in the ordinary Application suite and full `./bin/build`.
No tooling tests or coverage exclusions are added. For a focused non-TTY run in the package PHP container:

```sh
docker run --rm -v "$PWD:/app" -w /app --user "$(id -u):$(id -g)" fight-access-control \
  php -d zend.exception_ignore_args=0 vendor/bin/phpunit --fail-on-skipped --no-coverage \
  --filter 'InMemory(DeliveryLifecycle|DeliveryWithoutLookup|ProtectedSink)ConformanceTest'
```

`bin/phpunit` uses a TTY; this equivalent direct invocation supports automation. The complete gate still uses the
unmodified `./bin/build`. Exception arguments are deliberately enabled in focused safety proof.

Consumer binding steps:

1. Use the **exact candidate's** production code and source checkout's `tests/` tree. Register the development-only
   PSR-4 mapping `Fight\\Test\\AccessControl\\` to that checkout's tests directory; dependency autoload-dev is not
   automatically inherited. Do not add test classes to production autoload or pair these suites with older APIs.
2. Implement `DeliveryConformanceFixture` over the consumer's real repositories, shared transaction connection,
   authority writers, key adapters, audit, dispatcher and protected sink. `ports()` returns public capabilities only.
   The fixture starts with exactly one real `AgentProvisioningService` issuance, a current authenticated originator
   and a distinct currently delegated delivery worker. Use isolated test-owned storage, keys and registered slots.
   Provision test key aliases `test-key-v1` and `test-key-v2` against actual test encryption keys; these are not paths.
3. Bind both abstract suites in the consumer test project, with the reference runners' coverage metadata if required.
   A sink implementing optional `AgentCredentialReceiptLookup` must reconcile receipts before decryption. A sink
   without it repeats original bytes/ID under a **fresh** admission; assertions adapt to the declared capability,
   not skipped cases. Both profiles run locally. Recovered admission alone never permits materialization in either.
4. Run every inherited case without replacing assertions. Run the predecessor focused obligations in the evidence
   map as well, with real adapters for the boundaries they own. Save candidate and adapter versions, complete logs,
   exit results, persistent before/after observations and the injected schedule. A test name or mock pass is not
   evidence that a consumer integration ran.
5. Independently qualify the consumer activation/use boundary below and the separate migration/cohort/restore
   work before claiming support. This checkout does not authorize those executions, adoption or release.

## Fixture semantics and real fault injection

[`InMemoryDeliveryConformanceFixture`](../tests/Application/AccessControl/Agent/Service/Conformance/InMemoryDeliveryConformanceFixture.php)
is a reference **behavioral model**. Retained repositories/sink model durable storage; `restart()` clears transient
faults/hooks and each suite helper constructs new Application services. It is not an OS kill, database reconnect,
real crypto or independent worker process. Do not copy its callbacks into production or cite them as database proof.

| Fixture boundary | Consumer obligation |
| --- | --- |
| `ports()` | Share the supported package-owned transaction across persistence, audit and transactional authorization. Reject nested transactions. Instrument sink support/lookup/stage/verify/cleanup to prove they run outside **every** database transaction; materialization also follows confirmed admission. Rewrap is instead inside the fenced maintenance transaction. |
| `allow`, `changeAuthority` | Use actual caller, Permission, delegation and destination writers. Changes advance authoritative epochs and honor held fences, including revoke/regrant ABA. Supplied test strings/booleans are setup instructions, never production authorization evidence. |
| `pause(claim/admission)` | Synchronize after the selected claim/admission commit before returning to the suspended caller. A late admission return can cross the recorded deadline. Execute the competing writer independently; callbacks are one-shot, not repeated by nested successor work. |
| `pause(materialized/invoking/accepted/verifying)` | Suspend after decipher, immediately before sink acceptance, after durable acceptance, or before receipt verification. Coordinate actual competing lifecycle/authority writers or a restarted worker; retain both durable outcomes. |
| `pause(fenced)` | Start an independent authority writer while the claim transaction holds its authority fence. It must complete after that transaction; admission then sees the changed authority. Repeat this schedule for every real writer path and both winning orders. |
| `loseCommit(stage, persist)` | Lose the Nth subsequent transaction acknowledgement after callback completion, with separately observable committed and rolled-back outcomes. Never fabricate the service result. Claim/admission loss must not materialize; outcome loss preserves original receipt/bytes. |
| `failWrite(stage)` | Fault after a delivery write but before commit; independently verify rollback. This is different from unknown commit outcome. |
| `restart` | Retain only durable storage, sink state, authority and independent observers; discard request caches/services/faults. Real qualification closes/reopens connections and restarts a worker process. Scheduler input is authenticated scope/destination, not a saved result, event or claim token. |
| `advance`, `expireAuthorityAfter` | Control trusted test time, including authority expiry earlier than lease/admission. Verify real deadline checks and clock rollback policy in consumer composition, not wall-clock sleeps in package tests. |
| `keyFailure`, `corruptMaterial` | Inject typed key failures, corrupt persisted ciphertext or copy another operation's ciphertext while preserving original metadata/revision. Exercise real authenticated associated-data rejection, not a consumer fake returning the expected classification. |
| `loseSinkResponse`, `forgetSinkMaterial` | Persist exact sink acceptance before dropping the response; separately remove material after confirmed delivery. Neither outcome may regenerate issuance or use the authentication envelope as fallback. |
| `failPublishers` | Throw the returned exact fault from both publication attempts. Revocation must rethrow the original fault after durable retirement, unlike provision/rotation's scoped warning behavior. |
| `stored`, `receipt`, `highWater`, `counts` | Load independently from durable state and observer instrumentation, not expected values. Counters include attempted decryptions/stage calls, transactions, generation, audit and publication; uncommitted generation is not a persisted issuance. |
| `preparedBytes`, `stagedBytes` | Privileged fixture-only oracles. Never feed these bytes into worker recovery or add a production secret-read endpoint. Normal workers receive material only through package admission and the bound cipher. |
| `authenticate` | Create a fresh signed request and run the package current-principal flow. Reference proof uses the real provider with controlled signature/nonce adapters; real bindings must use their actual authentication adapters. Authentication is not destination selection or launch authority. |
| `forbiddenValues`, `safeEvidence` | Retain nonempty test-secret, ciphertext, provider/path sentinels including retired material. Return actual safe audit/event/diagnostic representations. Capture real logging/tracing/exception adapters separately; do not log test-oracle bytes. |

Real PostgreSQL or equivalent qualification requires independent sessions/processes and barriers around the shared
locks/CAS boundaries, actual commit/rollback/connection loss, contention and deadlock outcomes. Run all public/direct
lifecycle writers and every administrative/CLI/background authority writer; one compliant writer does not fence an
unqualified one. Record connection identities, transaction depth, isolation level, blocked/unblocked ordering and
persisted rows. Sink qualification separately proves atomic immutable staging, durable opaque receipts, monotonic
slot order and same-ID full-binding tombstones under real concurrent requests. Unsupported capabilities fail closed.

## Evidence map: D1–D7

The exact executed results and local gate receipt belong to
[TASK-00054's checkpoint](../planning/tasks/00054-TASK.md#implementation-and-verification-checkpoint).
Names below locate assertions, not independent acceptance. `Lifecycle` means `DeliveryLifecycleConformance` and
`Sink` means `ProtectedSinkConformance`; existing focused suites remain in
[`tests/Application/AccessControl/Agent/Security/`](../tests/Application/AccessControl/Agent/Security/).

| Requirement | Reusable integrated proof | Focused predecessor proof retained |
| --- | --- | --- |
| **D1 discovery/restart** | Lifecycle `test_default_restart_skips_an_entire_obsolete_batch_without_manual_cleanup`, `test_restart_resolves_each_uncertain_delivery_commit_without_reissuing` (provision and rotation); status assertions prove no writes/publication/materialization | `AgentDeliveryRecoveryServiceTest::test_default_only_restarted_scheduler_discovers_and_delivers_without_caller_or_event_input`, `test_restart_after_each_durable_boundary_uses_only_committed_state`; `ListDueAgentDeliveriesHandlerTest`; TASK-00049 `test_both_publishers_and_caller_can_disappear_before_scheduler_only_recovery` still owns both publishers/caller termination |
| **D2 admission authority** | Lifecycle `test_authority_changes_deny_early_or_leave_only_inert_late_bytes` (four changes × five boundaries), `test_fenced_authority_writer_and_aba_require_a_new_admission`, `test_earliest_authority_deadline_guards_materialization_invocation_and_completion`; retirement cases add credential changes at the same boundaries | `AgentCredentialDeliveryServiceTest::test_current_authority_rejects_early_and_late_unauthorized_work`, `test_deadline_guards_sensitive_effects_and_acknowledgement`, `test_outer_transactions_and_missing_worker_delegation_or_target_authority_fail_closed`; recovery `test_current_receipt_reconciliation_rejects_revocation_aba_and_expiry_before_acknowledgement`; real activation/use remains separate |
| **D3 uncertainty/takeover** | Lifecycle `test_restart_resolves_each_uncertain_delivery_commit_without_reissuing` (three stages × both outcomes × both issuance kinds), `test_each_failed_persistence_stage_rolls_back_and_scheduler_recovers`, `test_lost_response_takeover_and_delivered_secret_loss_never_trigger_secret_reread`; lookup and non-lookup profiles | `AgentDeliveryRecoveryServiceTest` uncertain-commit, crash-boundary, receipt-only and takeover cases; `AgentCredentialDeliveryServiceTest::test_competing_claim_and_takeover_cannot_acknowledge_an_old_admission` |
| **D4 sink identity/order** | Sink `test_every_immutable_receipt_or_idempotency_field_and_bytes_reject_mismatch` (all 12 tuple fields plus bytes), `test_actual_rotation_orders_late_predecessors_across_slots_without_selecting_old_authority` (same/cross slot × both arrival orders), `test_equal_operation_ids_and_reassigned_slots_keep_global_order_and_exact_receipts`; Lifecycle cleanup/replay | `AgentProtectedSinkContractTest` equal-order collision, missing/swapped receipts and cleanup; `AgentCredentialRotationServiceTest::test_successor_delivery_wins_over_an_older_in_flight_invocation`; consumer exact activation/use selection still required |
| **D5 all-path retirement** | Lifecycle `test_every_retained_lifecycle_writer_fences_real_delivery_and_original_status` (service/direct rotation/revocation × five boundaries), real fresh principal rejection and original safe status; `test_revocation_publication_failure_remains_a_throw_after_durable_retirement` | `AgentCredentialRetirementTest` atomic rollback, all-path successor validation and failure redaction; `AgentCredentialRotationServiceTest::test_rotation_fences_real_delivery_and_late_staged_bytes_never_acknowledge`; `AgentRotationAuthenticationTest` nonce/revision fencing; rejected legacy rotation signatures in Domain/lifecycle tests |
| **D6 secrets/keys/terminal recovery** | Lifecycle `test_rewrap_key_failure_and_restart_preserve_original_binding_without_envelope_fallback` (temporary, permanently missing, corrupt, swapped), `test_rewrap_during_delivery_fences_stale_completion_and_retains_retry_history`, `test_retention_cleanup_and_replay_preserve_correlation_and_slot_order`, lost delivered material test; shared safe serialization/JSON/debug/trace assertions | `AgentDeliveryMaintenanceServiceTest` uncertain maintenance commits, key references/closure, stale rewrap versus retirement, lost cleanup responses and rebound-successor cleanup; `AgentCredentialDeliveryServiceTest::test_invocation_and_results_do_not_serialize_or_debug_secret_material`; Domain invocation/material safety |
| **D7 finite bounds/capacity** | Lifecycle `test_invalid_overrides_reject_without_work` (16 cases), default 51-operation pre-limit selection, `test_valid_overrides_pin_retry_retention_and_cleanup_without_manual_approval`, `test_capacity_policy_and_storage_outage_do_not_disguise_existing_recovery` | `AgentDeliveryTest`, `AgentDeliveryDiscoveryTest`, `AgentMaintenanceTest`; recovery scheduler one-batch and retry bounds; `AgentMaintenanceQueryTest` keyset pages and global reference counts; TASK-00049 capacity/same-key recovery |

Direct repository rotation here tests the retained cancellation contract, not a complete replacement issuance: the
full service test supplies the atomic successor operation/audit. The no-key/sink assertion concerns predecessor
cancellation; normal successor issuance still uses its own generator/ciphers. A revoked credential may remain
addressable by repository identity, but the actual current-principal flow rejects it. Raw legacy aggregate/service
rotation rejects rather than creating an unfenced alternate issuance path.

The finite defaults/ranges remain those in [delivery](agent-credential-delivery.md),
[recovery](agent-delivery-recovery.md#finite-defaults-and-overrides) and
[maintenance](agent-delivery-maintenance.md#bounded-discovery-retention-and-cleanup): 60-second lease, 15-second
admission, 30-second retry, 86400-second retention, 100 attempts, 50-item discovery/maintenance batches,
30-second poll and 86400-second cleanup grace after original retention. Overrides are pinned where specified;
recovery does not reset them. Capacity gates new issuance, not existing status/discovery/recovery. Outages remain
unavailable/indeterminate, not empty work or success. At-least-once effects and no extra routine human approval remain
unchanged; no new default is selected here.

## Consumer activation/use binding — mandatory, not executed here

Bind the following **additional scenarios** to the consumer's real activation transaction and broker/use entry
point, not a new package service or a boolean fixture implementing enrollment. Reuse the suites' original/successor
issuances, delivery fault barriers and persisted receipts as setup. Invoke the actual consumer APIs and independently
read activation state and launch/use effects. Repeat for every exposed consumer path, with real current authority.

| Input / interleaving | Required independent consumer observation |
| --- | --- |
| Confirmed issuance (including publication warning), pending/retryable/indeterminate delivery | No activation, launch or signing authority; safe metadata is not a capability. |
| Exact verified receipt and confirmed package delivery, but no current enrollment authorization | No activation. A sink receipt/selection pointer alone cannot grant it. |
| Exact confirmed delivery plus current transactionally fenced authorization | One separately confirmed activation, not an inference from delivery. Lose activation commit acknowledgement: resolve activation's own outcome before use. |
| Caller/Permission/delegation/destination/credential changes before activation or use, including ABA | Deny stale activation and subsequent use. Persisted old admission/receipt remains inert; all consumer authority writers share the fence. |
| Package rotates to another slot or reassigns a slot across Agents/scopes before the sink learns it | Select only the exact currently authorized package/destination tuple, never newest arrival or sink high-water pointer. Old bytes/receipts cannot activate or sign. |
| Already admitted invocation finishes after revocation/expiry | It may stage fixed-sink inert bytes, but cannot acknowledge, activate, launch or use retired authority. No cross-system rollback claim. |
| Previously activated Agent makes a later broker request after Permission removal/revocation | Re-resolve current consumer use, credential and destination authority; deny even if the old credential once authenticated or a receipt remains. |
| Lost delivered material, terminal keys or post-cleanup replay | No automatic issuance/rotation, envelope fallback or revived activation. Explicit reconciliation remains separate. |

Retain the consumer candidate/adapter versions, real writer inventory, connection/barrier traces, exact-tuple
observations, sanitized API outcomes, persistent activation outcomes and absence/presence of actual launch/signing
effects. Missing evidence is an adoption blocker, not a reason to fabricate passing package tests or implement a
consumer enrollment replica in this library.

## Qualification limits

Local reference executions establish real package coordination over modeled persistence/sink/authority interleavings,
including shared fences and takeover; they do not establish physical concurrency, transaction isolation, real crypto,
OS process termination, sink durability, audit backend logging, activation or use. Passing lookup and non-lookup
profiles does not promise exactly-once materialization/disclosure. Consumer runs must repeat required scenarios on
their actual composition without substituting these doubles.

[TICKET-00014's scenario map](../planning/tickets/00014-TICKET.md#scenario-ownership-and-evidence-traceability)
retains real-consumer proof and TASK-00055–00059 ownership for existing-Agent state, cohorts, the single canonical contract,
restoration and final migration evidence. No rollback/restore rehearsal, migration, dependency upgrade, tag, release,
consumer support or Agent OS TASK-00138 closure is claimed by these suites.
