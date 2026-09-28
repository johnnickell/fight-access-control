# Safe Agent operation status (unreleased v0.5.0 work)

TASK-00047 adds `GetAgentOperation` and `GetAgentOperationHandler` to the
[recoverable provisioning contract](agent-provisioning-operations.md). It is a **read-only, secret-free snapshot**,
not another issuance workflow or a credential-retrieval API. The incomplete replacement remains unreleased and
not deployable. Rotation, delivery, lifecycle, cohort/migration and consumer qualification remain downstream.

## Composition and authority

Register `GetAgentOperationHandler::queryRegistration()` with Common's QueryHandler registration. Construct the
handler with the Domain `AgentOperationRepository` and request-scoped Application `AgentOperationAuthorization`.
There is no Unit of Work, cipher, generator, sink, audit repository, event dispatcher or admission-limit dependency.

```php
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentOperation;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

// Use the original retained key and registered destination, not a name, path or URL.
$query = new GetAgentOperation($retainedKey, $registeredDestination);
$view = $handler->handle(QueryMessage::create($query));
if (!$view->isConfirmed()) {
    // Outcome remains indeterminate. Keep the original key/request for service resolution.
    // Do not infer rollback, choose a new key, or report delivery or launch permission.
}
```

The consumer supplies the real authenticated invoker independently of the Query. Trusted namespace, originating
caller type/ID and registered destination identify the request; even correctly formed or serialized identifiers
are **not authority**. An explicitly delegated worker authenticates as itself and retains the originator's scope.
Do not deserialize an untrusted actor string and treat it as proof of identity.

`AgentOperationAuthorization::authorizeRead(scope, destination, target)` is a separate read operation on the same
consumer policy capability used for issuance. It must not call the transaction-only `authorize()` method, start or
commit a transaction, write an audit fact or mutate authority. Implementers must add this method when adopting the
unreleased contract; there is no permissive compatibility default.

1. Before repository lookup, `authorizeRead(..., null)` checks current scope/caller or explicit delegation and
   destination binding. Wrong namespace/caller, revoked authority, expired delegation and denied destinations reject
   without reading an operation, whether it exists, is absent or is terminal.
2. `getStatusByKey()` returns a coherent authoritative **safe projection**, not a material-bearing aggregate.
3. Immediately before disclosure, `authorizeRead()` rechecks the scope/delegation/destination and, when present,
   the original Agent target. It also repeats the check when storage returned no record. Do not cache the earlier
   allow. Terminal or retired work does not bypass current target/destination policy.
4. The Domain view verifies the exact scoped key/destination binding and supported historical request version.
   A binding mismatch denies without exposing a conflicting operation. Unsupported versions reject only after
   current target authorization, without selecting a current canonicalizer or changing retained request evidence.

A read returns the stored snapshot observed by the repository; it does not freeze subsequent lifecycle/authority
changes. Authorization changes observed before the final check deny. Later changes do not retroactively recall an
already authorized response. This is **not** the transactional admission fence required for delivery or activation.
Consumers must check current authority again for those operations and each credential use.

## Query and result shapes

`GetAgentOperation::fromArray()` / `toArray()` use six required fields:

| Field | Type and bound |
| --- | --- |
| `namespace` | 1–64 ASCII identifier characters |
| `caller_type` | 1–32 ASCII identifier characters |
| `caller_id` | 1–128 ASCII identifier characters |
| `operation_id` | UUID |
| `destination_id` | Registered destination UUID |
| `destination_revision` | Positive integer |

The identifier alphabet is `A–Z a–z 0–9 _ . : -`. These reuse provisioning's finite value-object bounds. Missing/null
fields, wrong scalar types, invalid UUIDs and invalid revisions reject with sanitized `INVALID_REQUEST`; there are
no permissive casts from numeric strings, booleans or floats. `AgentOperationKey`, `AgentCredentialDestination` and
`AgentIssuance` now also supply canonical safe array round trips. They do not confer authority when reconstructed.

`AgentOperationView` has named getters plus `fromArray()` / `toArray()`. Its exact top-level shape is:

| Field | Confirmed record | Indeterminate record |
| --- | --- | --- |
| `key` | Original namespace/caller type/caller ID/operation ID | Same retained key |
| `issuance_outcome` | `confirmed` | `indeterminate` |
| `canonical_version` | Persisted original request version (currently supported: `1`) | `null` |
| `issuance` | Original `AgentIssuance::toArray()` | `null` |
| `delivery_disposition` | Recorded delivery enum below | `null` |
| `credential_disposition` | Recorded credential enum below | `null` |

Issuance contains `namespace`, `caller_type`, `caller_id`, `operation_id`, `delivery_id`, `agent_id`, `credential_id`,
`credential_revision`, `destination_id`, `destination_revision`, `destination_write_version`, and `issued_at`.
The credential revision is non-negative; the write order is positive. The canonical timestamp format is
`Y-m-d\TH:i:s.uP`, including microseconds and an explicit offset. Invalid or normalized-overflow dates reject.
A result cannot deserialize an indeterminate outcome with guessed issuance/disposition, or a confirmed issuance
whose key differs from its top-level key. Missing result fields and invalid enums reject safely.

- `AgentDeliveryDisposition`: `pending`, `delivered`, `retired`, `expired`, `retryable`, `terminal`.
  `retryable` reports persisted recoverable delivery/key/storage failure without exposing a provider reason;
  `terminal` reports recorded terminal failure. `retired` means the delivery copy was retired without asserting
  delivery success. No-material alone is never evidence of delivery.
- `AgentCredentialDisposition`: `current`, `superseded`, `revoked`, always about the **original credential**.
  `current` is recorded lifecycle metadata, not permission to sign, activate an enrollment or launch.

Original issuance stays identical after supersession, revocation, expiry or delivery-copy retirement. No successor
credential is substituted. Publication warnings are transient service-result metadata, not stored delivery outcomes;
status does not reconstruct warnings from events. Even both publishers failing leaves authoritative issuance readable.
Neither a confirmed result nor a `delivered` value supplies enrollment activation, launch permission or secret access.

## Storage, uncertainty and bounded recovery

`AgentOperationRepository::getStatusByKey()` must project one coherent authoritative snapshot of original issuance,
retained canonical version and recorded dispositions. Include permanent tombstones and unknown versions. Never use
an eventually consistent replica, load/decrypt material, acknowledge work, advance a lifecycle, or apply new-work
capacity limits. It must throw on storage failure, not return null. Do not implement this method by calling the
transaction-fenced/material-bearing `getByKey()` path from a QueryHandler.

`AgentCredentialOperation` carries persisted delivery and credential dispositions for safe projection. Newly
provisioned records default to `pending` / `current`. Hydration must supply recorded values for later states; it must
not reset retired or delivered work to defaults. The existing `retireMaterial()` retains original correlation and
credential disposition, changes pending/retryable copies to `retired`, and preserves already-recorded delivered,
expired or terminal outcomes. Lifecycle writers still own atomic disposition updates under their expected-state
fences; this TASK adds **no** delivery, rotation or revocation writer. The repository must persist dispositions with
those writers and retain them in tombstones. Unknown canonical versions remain stored but deny public lookup.

| Observation | Public outcome | Recovery meaning |
| --- | --- | --- |
| Authoritative committed record | Confirmed original issuance and recorded disposition | No issuance, generation, audit or event repeats |
| Authoritative absence | Indeterminate view; no guessed identity/disposition | Absence is not proof of rollback; retain the key |
| Storage or integration unavailable | Sanitized retryable `AgentOperationRejectedException(UNAVAILABLE)` | Retry status or original service request after recovery; no fabricated result |
| Current authority denied or binding mismatch | Sanitized `UNAUTHORIZED` | No conflicting/existing outcome disclosed |
| Unsupported retained request version | Sanitized `UNSUPPORTED_VERSION` | No rewrite, current canonicalization, mutation or new issuance |
| Prior known pre-commit service rejection | Remains the original service's rejection, not reconstructed by this query | An absent record still cannot prove why an earlier attempt left no record |

The query never infers outcomes from missing success/failure events. After lost commit acknowledgement, an actually
committed record becomes confirmed; an actually rolled-back attempt remains indeterminate to the read path. The
owning provisioning/rotation service reconciles the same original key/request when a mutation decision is needed.
No automatic key replacement or reissue occurs here. New-work capacity exhaustion and invalid issuance-limit
overrides do not gate status. There are no read-policy overrides requiring manual setup or another human approval.

Dependency exceptions are sanitized without chaining their original throwable. Status arrays, ordinary object
debugging and serialized views contain only the allowlisted safe values: no raw secret, authentication envelope,
delivery ciphertext, request digest, key version/path, provider message, receipt, decryption or secret-read handle.
`fromArray()` inputs are sensitive trace parameters so rejected arbitrary payloads do not enter package factory
traces. Consumers remain responsible for their own logging and caller-stack arguments; never log raw secrets there.

## Executable evidence and limits

[AgentOperationViewTest](../tests/Domain/AccessControl/Agent/AgentOperationViewTest.php) proves canonical round trips,
required fields, strict types, finite identifiers, date/enum rejection, historical version/binding checks and safe
retirement projection. [GetAgentOperationHandlerTest](../tests/Application/AccessControl/Agent/QueryHandler/GetAgentOperationHandlerTest.php)
proves the public read path against controlled repository/consumer-policy fixtures:

| TICKET-00012 evidence | Test |
| --- | --- |
| I1, I7 lost response/restart, original pending identity and no effects | `test_lost_response_and_restart_read_original_issuance_without_repeating_any_effect` |
| I5 worker's own identity and current explicit delegation | `test_explicit_delegated_worker_retains_original_scope_and_target_authorization` |
| I5 concealment for existing, missing and terminal work | `test_denied_requests_conceal_existing_missing_and_terminal_state_before_lookup` |
| I5 target checks and authority changes during lookup | `test_target_denial_and_authority_changes_during_lookup_prevent_disclosure` |
| I1 safe persisted dispositions and unchanged original identity | `test_persisted_disposition_fixtures_preserve_original_issuance_without_worker_claims` |
| I6 retained/tombstoned and unknown-version keys | `test_tombstones_and_unknown_versions_preserve_request_binding_without_canonicalization` |
| I5, I7 wrong repository correlation and destination binding | `test_mismatched_repository_key_or_destination_never_exposes_other_operation` |
| I1, I7 actual provision commit uncertainty and known pre-commit rejection | `test_uncertain_commit_and_known_precommit_rejection_never_turn_absence_into_rollback` |
| I1, I7 both publishers failing, no inferred delivery | `test_both_publishers_failing_cannot_hide_issuance_or_prove_delivery` |
| I6 new-work capacity and invalid overrides preserve status/retry | `test_new_work_capacity_and_override_validation_do_not_gate_existing_status` |
| I7 safe storage/authorization failure, no material reads or writes | `test_storage_and_authorization_faults_are_sanitized_unavailable_not_absence` |

Provision-to-read and uncertain-provision-to-read cases exercise real package code with in-memory persistence and
transaction fixtures. All delivered, superseded, revoked, expired, retryable and terminal **disposition fixtures**
prove read projection only, not their downstream writers or valid lifecycle transitions. TASK-00049 will exercise
actual rotation/delivery transitions after those writers exist. No scheduler-only recovery, real database
concurrency, consumer authorization policy, sink, encryption, migration, activation or launch qualification is claimed.
This contract has no HTTP endpoint/envelope or UI; executable state/failure evidence is the useful before/after proof.
