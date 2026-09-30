# Agent issuance-recovery conformance (unreleased v0.5.0 work)

[TASK-00049](../planning/tasks/00049-TASK.md) exposes a consumer-bindable **test** contract and runs it against
package-controlled behavioral adapters. It composes the actual provision, rotation, status, discovery, delivery,
maintenance, revocation and current-principal paths. It adds no production API, Adapter namespace, endpoint,
consumer Permission or alternate workflow. “Publish” means available in this checkout, not a package release.

## Run and bind

The reusable suite is
[`IssuanceRecoveryConformance`](../tests/Application/AccessControl/Agent/Security/IssuanceRecoveryConformance.php).
Its only abstract method is `newFixture(): IssuanceRecoveryFixture`. The reference subclass is
[`InMemoryIssuanceRecoveryConformanceTest`](../tests/Application/AccessControl/Agent/Security/InMemoryIssuanceRecoveryConformanceTest.php).
Run it using PHP 8.5 and the checkout's installed PHPUnit:

```sh
php vendor/bin/phpunit --no-coverage \
  tests/Application/AccessControl/Agent/Security/InMemoryIssuanceRecoveryConformanceTest.php
```

This is product behavior/conformance, included in the ordinary Application suite and `./bin/build`; it is not
release-tooling qualification. In the isolated package runtime, the same command can be passed directly to
`docker run --rm -v "$PWD:/app" -w /app fight-access-control`. `bin/phpunit` uses an interactive TTY; automation
without a TTY should use this direct container invocation rather than weakening the product gate.

A consumer:

1. Installs the **exact candidate's production code** and retains that same source checkout's `tests/` tree. This
   contract is unreleased; do not pair it with an older installed package and infer compatibility.
2. Adds the development-only PSR-4 mapping `Fight\\Test\\AccessControl\\` to that checkout's `tests/` directory in
   its test bootstrap/autoload-dev. Dependency autoload-dev mappings are not automatically inherited. Do not add the
   test namespace to production autoload or copy lifecycle policy into a consumer wrapper.
3. Implements
   [`IssuanceRecoveryFixture`](../tests/Application/AccessControl/Agent/Service/Conformance/IssuanceRecoveryFixture.php)
   using its actual repositories, transaction/authority adapters and controlled test sink. Returns
   [`IssuanceRecoveryPorts`](../tests/Application/AccessControl/Agent/Service/Conformance/IssuanceRecoveryPorts.php),
   containing only public Domain/Application/Common capabilities. The suite itself constructs package services.
4. Extends `IssuanceRecoveryConformance`, implements `newFixture()`, and uses the reference subclass's coverage
   metadata when its PHPUnit configuration requires it. Each fixture must own a fresh isolated dataset, destination
   and authenticated context; UUID fixtures come from the owning factories.
5. Runs all inherited cases without replacing assertions or skipping unsupported capabilities. Saves the exact
   candidate, adapter/database/sink versions, command/result, isolation level, connection identities, barriers,
   injected fault boundaries and persisted observations. Failures remain failures; no reference-run result qualifies
   a consumer's adapters.

### Fixture obligations

The fixture is setup, fault injection and independent observation, **not an implementation of issuance/recovery**.
Use real transactions and public writers in consumer bindings. `allow()` establishes authoritative local scope and
registered-destination grants for an authenticated maintainer. `delegateWorker()` establishes a different authenticated
worker and explicit original-scope delegation; it must not impersonate the initiating caller. No consumer policy may
accept supplied strings/booleans as its production authorization decision.

- `ports()` supplies repositories, audit, authorization, generator/ciphers, delivery authority/sink, clock, transaction
  and dispatcher sharing the supported composition. Production adapters must redact sensitive parameters as required
  by the [retirement contract](agent-credential-retirement.md#composition-and-atomic-cancellation).
- `restart()` discards initiating services, request/result caches, publisher memory and faults; durable storage/sink
  state and test-observer counters survive. A real binding closes/reopens connections and recreates the worker in an
  independent process. Its scheduler receives only authenticated scope/destination configuration—not an issuance
  result, event, request, operation key, or saved claim token. The test retains expected issuance/bytes only as an oracle.
- `loseCommit()` injects connection/acknowledgement loss **after the callback finished**, once, with both committed
  and rolled-back storage outcomes. It must not fake the result returned by the package service. The same seam loses
  the first recovery claim acknowledgement and leaves a real expired claim for restart discovery.
- `failBeforeCommit()` faults the authorization participant, persistence after insertion, or audit after insertion.
  Independent observations must show rollback of all writes, predecessor retirement, reservations and participant data.
  Cipher-failure boundaries also remain mandatory in the predecessor suites listed below.
- `state()` independently reads counts, reservation order, credential-state fingerprints, retained issuance/canonical
  binding/credential-disposition fingerprints and audit-fact fingerprints. Do not synthesize expected values. Hashes
  protect fixture credential material; they are not public status fields. Delivery history is observed separately via
  `stored()`, exact receipts and safe queries. `transactions()` proves status reads enter no transaction.
- `contend()` runs requests against one shared storage authority, returning each result or throwable. Real bindings
  use independent sessions/processes and barriers around absent-key lookup, unique insertion and predecessor CAS.
  Test identical keys, changed bindings, competing rotation keys and rotation versus revocation. Do not “qualify” a
  database by copying the reference binding's serialized schedule.
- `contendWithAuthorityRevocation()` starts a real grant writer after issuance acquires its authority fence; that
  writer must complete after issuance and the next retry/read must deny. Repeat for every consumer authority writer,
  including worker, CLI, direct-service and administrative paths. Cached external allows are unsupported.
- `preparedBytes()`/`stagedBytes()` are privileged test-oracle observations only. Never expose them to application
  callers or supply them as the worker's recovery input. The worker obtains original material through admitted delivery.
  `loseSinkResponse()` stages before losing the response; optional receipt lookup may avoid a second invocation.
  Without lookup, repeat invocation must retain the original bytes, ID and exact tuple. `invalidateReceipt()` causes
  terminal rejection, never false delivered success. Cleanup must retain sink tombstones and reject delayed replay.
- `setCanonicalVersion()` is a storage fixture for an unsupported version, preserving the binding. It is not an
  upgrade tool. TASK-00057 owns single-contract normalization, restart and retained-key safety proof.
- `forbiddenValues()` lists nonempty fixture secrets, ciphertext, key paths and provider diagnostics. `safeEvidence()`
  includes actual event/audit/diagnostic representations. Assertions inspect serialization, JSON, debug and failures;
  consumer logging, tracing and provider error adapters require their own captures too.

## Outcome and authority separation

The combined publication scenario runs for **both provision and rotation**, with pending and abandoned-claim variants.
It confirms one transaction/issuance/audit and a typed `PUBLICATION_FAILED` warning even when both publishers throw.
Safe status is still pending and no sink bytes exist. After dropping caller inputs and publisher memory, the actual
bounded discovery → claim → admission → sink → receipt-confirmed outcome path delivers without caller retry. A second
pass is empty; the original tuple, audit fact and credential authority are unchanged. Neither a response nor a receipt
asserts enrollment activation or launch permission.

`test_actual_delivery_then_revocation_retains_original_status_without_authority` runs the consumer-bindable
`authenticateDelivered()` obligation: a **new request** authenticates delivered material against current authority;
after real revocation, another new request rejects despite a retained receipt and sink bytes. The reference binding
uses the real `CurrentAgentPrincipalProvider` with controlled signature/nonce/repository adapters. It proves package
credential-authority revalidation, **not** consumer destination authorization, enrollment or launch approval.

Before consumer support, additionally run the actual enrollment/broker boundary with these required observations:

| Consumer scenario | Required outcome |
| --- | --- |
| Confirmed issuance or publication warning, no confirmed exact delivery | No activation or launch; metadata cannot act as an authorization capability. |
| Confirmed exact receipt and current transactional enrollment authority | Only an independently confirmed activation may be recorded; receipt alone is insufficient. |
| Caller/delegation/destination revoked or rebound before activation, including revoke/regrant ABA | Deny stale activation; activation participates in the shared authority fence. |
| Already staged bytes/receipt after rotation, revocation or cross-scope slot reassignment | Deny obsolete activation and use, even before the sink learns the newer slot version. |
| Previously activated credential used in a later broker request | Resolve current exact credential/destination and use authority again; never reuse the issuance or activation allow. |
| Permission/use authority withdrawn without changing the receipt | Deny launch/use; authentication alone is not launch permission. |

Run these against the consumer's real adapters, activation writer and broker entry points, with barriers around each
fence and both winning orders. Retain separate activation/use result evidence. No enrollment implementation is added
here, and no such real consumer run has occurred. TASK-00054 owns the wider delivery/admission/sink conformance;
consumer activation/use proof remains mandatory before adoption.

## Evidence map

All method names below refer to `IssuanceRecoveryConformance` unless a predecessor class is named. The reference run
is recorded in TASK-00049, with full logs/receipt in its ignored run directory. Source names alone are not a pass.

| TICKET-00012 | Integrated/reusable evidence | Focused predecessor evidence retained |
| --- | --- | --- |
| I1 normal issuance, status, separate outcomes | `test_both_publishers_and_caller_can_disappear_before_scheduler_only_recovery`; `test_actual_delivery_then_revocation_retains_original_status_without_authority`; terminal/expiry tests below | [provisioning evidence](agent-provisioning-operations.md#package-evidence-and-consumer-qualification), [rotation evidence](agent-rotation-operations.md#evidence-and-limits), `GetAgentOperationHandlerTest` read-only/current-target checks |
| I2 original-key recovery, canonical binding, uniqueness | `test_same_key_retry_preserves_fact_identity_and_current_authority`; `test_retained_binding_conflicts_and_unknown_versions_never_issue`; `test_contending_same_key_requests_resolve_one_issuance`; `test_contending_different_bindings_leave_one_winner_and_one_conflict`; `test_competing_rotation_keys_cannot_both_replace_one_predecessor` | `AgentProvisioningServiceTest::test_unique_loser_rolls_back_then_resolves_or_conflicts_with_winner`; `AgentCredentialRotationServiceTest::test_concurrent_loser_rolls_back_then_resolves_only_the_authoritative_winner` inject actual loser rollback/winner visibility rather than just serialized calls |
| I3 rollback and uncertain outcomes | `test_precommit_failure_rolls_back_every_participant_and_reservation`; `test_indeterminate_commit_resolves_original_request_after_restart`; `test_outer_transaction_cannot_wrap_an_issuance_service` | Both issuance service suites' precommit audit/encryption/persistence failures and committed/rolled-back uncertain-commit tests; closed capability checks |
| I4 publication independence, no caller | `test_both_publishers_and_caller_can_disappear_before_scheduler_only_recovery` (both kinds × pending/abandoned); `test_same_key_retry_preserves_fact_identity_and_current_authority` | Both issuance service suites' publisher-failure tests; `AgentDeliveryRecoveryServiceTest` crash boundaries and receipt-first recovery |
| I5 fresh authority, delegation, reservation, writers | `test_authority_writer_and_issuance_have_one_fenced_order`; `test_discovery_requires_delegation_and_cannot_cache_admission_authority`; `test_untrusted_scope_and_destination_cannot_read_or_resolve_an_existing_key`; `test_equal_ids_in_different_scopes_share_monotonic_slot_order_not_issuance`; `test_rotation_and_lifecycle_writer_never_leave_a_recoverable_revoked_credential` | Issuance authorization-participant rollback and target/delegation tests; `ListDueAgentDeliveriesHandlerTest`; `GetAgentOperationHandlerTest`; `AgentRotationAuthenticationTest` before/after nonce-boundary races |
| I6 defaults, overrides, capacity, retained keys | `test_capacity_preserves_status_resolution_and_bounded_recovery`; `test_expiry_and_cleanup_never_make_original_key_fresh_issuance`; unsupported-version cases above | `AgentOperationTest`, `AgentDeliveryTest`, `AgentDeliveryDiscoveryTest`, `AgentMaintenanceTest`; recovery-service bounded batches and maintenance key/cleanup tests |
| I7 safety, no fallback | Reusable safe-representation assertions; `test_lost_sink_response_recovers_original_bytes_and_id_not_issuance`; `test_terminal_delivery_retains_key_without_raw_or_envelope_fallback`; current-authority test above | Domain safe material/invocation tests, both issuance service suites' current-contract failure safety, `AgentCredentialRetirementTest`, `AgentProtectedSinkContractTest` |

The package does **not** implement an outbox or notification retry. Same-key retries emit no second success fact;
provision fact identity remains its original Agent ID, rotation its original Agent/credential/revision tuple. If a
consumer retries notifications, it must retain that fact identity and document at-least-once duplicate handling rather
than invoking issuance with a fresh key. The suite does not claim an unimplemented notification retry ran.

## Qualification limits

The reference binding retains in-memory repositories/sink as durable-state models while recreating service composition
and clearing publisher/fault state. It models caller termination, not an OS kill or a database reconnect. Its
`contend()` schedule is serialized; predecessor tests additionally inject loser rollback/CAS/writer interleavings.
Neither proves PostgreSQL locking, isolation, uniqueness, deadlock handling, true parallel scheduling or real
cryptography. It uses deterministic test secrets, a noncryptographic bound cipher and a controlled sink, never real
credentials. These are production-behavior assertions, not tests of the fixture implementation.

Real adapter, sink, authority-writer, activation/use, cohort and restoration runs are outstanding. No release,
consumer qualification, dependency upgrade or deployment follows from package tests. TASK-00068 removes superseded
legacy support; TASK-00058 implements package restoration guards. TASK-00059's
[complete evidence inventory](agent-operation-evidence.md) links current results and remaining consumer gaps.
The protocol remains unreleased and does not establish a qualified consumer deployment.
