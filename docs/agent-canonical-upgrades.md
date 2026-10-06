# Agent operation canonical contract (v0.5.0)

[TASK-00057](../planning/tasks/00057-TASK.md) establishes **one** canonical operation contract for initial adoption.
At the 2026-09-30 decision, John confirmed no deployed consumers or retained earlier-version operations needed
migration. The previous unreleased cross-version design is superseded: no v1 reader, version-selection API, reader set, switch-back or compatibility
shim remains. This is package behavior, not release, consumer qualification, enrollment activation or use authority.

## Supported representation

`AgentOperationCanonicalization::VERSION` is **2**, the sole supported persisted marker. It is a contract identifier,
not an Agent type or a selectable runtime mode. Marker `1` is unsupported, as is any unknown marker. Storage and
destination contract markers remain `1`; they describe different contracts.

The Domain owner `AgentOperationCanonicalization::name()` trims a fixed set at the edges: NUL, U+0009–000D,
U+0020, U+0085, U+00A0, U+1680, U+2000–200A, U+2028, U+2029, U+202F, U+205F and U+3000. This supports names pasted
with Unicode whitespace without depending on a changing Unicode property database. The normalized name must be
nonempty UTF-8 and at most 120 Unicode code points. Case and internal whitespace are preserved; U+200B/U+FEFF are
not trimmed and no NFC conversion is performed. New provisioning binds and stores the same normalized Agent name.

Request construction bounds raw UTF-8 input to 4096 bytes. New-work admission retains its 512-byte default and
optional validated override. A retained retry bypasses new-work capacity/raw-name admission limits, not current
authority or canonical validity. NBSP + `Runner` + ideographic space and ` Runner ` both bind `Runner`.

`AgentProvisioningRequest::canonicalize()` and `AgentRotationRequest::canonicalize()` take **no version argument**.
Persist marker `2` and the exact canonical JSON string, not just a digest:

- Provision: `["provision", normalizedName, destinationId, destinationRevision]`.
- Rotation: `["rotate", agentId, originalExpectedCredentialId, originalExpectedRevision, destinationId, destinationRevision]`.
- Encoding is PHP `json_encode(..., JSON_THROW_ON_ERROR)` with no other flags; preserve element order, integer types
  and escaped bytes. Identity strings use their value objects' canonical representations.
- Rotation retains the original predecessor. A retry does not substitute the successor or reapply the now-stale
  predecessor mutation precondition. Kind, target, predecessor and destination are always bound.
- Neither request currently has additional outcome-affecting options. Operational limits and delivery/maintenance
  policies are not additional issuance inputs.

The opt-in `Fight.AccessControl.AgentOperation.Confirmed` OpenAPI component permits only canonical marker `2`,
matching readable safe Views. Its field and reference names are unchanged; consumers still own transport.

## Lookup, authority and retained lifetime

Both issuance services hold the compatible cohort fence and obtain current trusted scope/destination (and rotation
target) authorization before lookup. Existing keys, including permanent tombstones, resolve through
`AgentCredentialOperation::resolve()`: validate the marker, compare the canonical request, return original issuance.
No generation, Agent mutation, binding rewrite, repeated audit or issuance event occurs. The resolution transaction
still runs. Only authoritative absence reaches new-work admission, using the fixed package marker and rules.

[Status reads](agent-operation-status.md) authorize before lookup and again with the original target before
validating the recorded marker and scope/destination binding. They neither open a transaction nor access material.
Readable retained state does not admit an incompatible writer or grant delivery, activation or use permission.
Wrong scope/caller, revoked authority or expired delegation denies before disclosure.

Unsupported markers, including `1`, reject with sanitized `UNSUPPORTED_VERSION` without migration, reinterpretation,
key reuse or fallback issuance. A malformed/nonmatching canonical request conflicts on retry. Storage failure remains
unavailable/indeterminate, never absence. Results and failures contain no raw secret, ciphertext, provider detail,
key path or secret-read capability. Confirmed issuance is distinct from delivery, activation and launch/use permission.

Completed, expired, revoked, superseded and cleaned operations retain their exact key, marker, request, original
issuance and lifecycle evidence. Cleanup removes only eligible material/inert sink copies; correlation and ordering
survive permanently. Removing speculative backward compatibility does **not** permit forgetting issued keys.

## Composition and initial adoption

The [cohort contract](agent-operation-cohorts.md) validates one persisted storage/canonical/destination profile,
monotonic generation and qualified actual capabilities. It has no `creationVersion`, `readerVersions` or creation
version getter. Both repositories must be compatible and agree on generation, sharing the transaction-duration
fence with authority writers. Missing or unsupported state rejects; no runtime default invents a missing cohort.

Current destination identity/revision, ownership, reservation order, sink tuple and global delivery ID are unchanged.
Cohort generation still fences stale delivery/cleanup acknowledgements; it cannot recall already disclosed bytes.
Routine operation/recovery needs no version choice or additional human approval. There is no historical canonical
inventory, migration/backfill, old-reader retention or cross-version rollout rehearsal required for initial adoption.
Do not use these APIs to convert stored operations or roll back external effects.

Actual persistence atomicity, writer fencing, key/sink behavior and current activation/use authorization still need
consumer qualification. After first deployment, stale restoration can conflict with external effects independently
of version upgrades; [TASK-00058](../planning/tasks/00058-TASK.md) retains restoration/reconciliation ownership.

## M3 executable evidence

The consumer-bindable [AgentCanonicalConformance](../tests/Application/AccessControl/Agent/Security/AgentCanonicalConformance.php)
uses `DeliveryConformanceFixture & AgentCanonicalFixture` with actual public services. Bind ports to real adapters,
expire delegation through its authority writer, and inject unsupported markers/corrupt requests without changing
other state. Restart must discard process composition while preserving durable operation/sink/authority state.
Independently observe records, Agent state, audits, counters and sink order; do not return canned service outcomes.

| Requirement | Executable scenario |
| --- | --- |
| Both issuance kinds, six retained states and fresh service composition | `test_restart_preserves_original_keys_in_every_retained_state`: pending, delivered, revoked, superseded, expired and cleaned; original identities/requests/Agent state, no duplicate generation/audit/fact/material/sink work |
| Changed name/kind/target/predecessor/destination conflicts | `test_changed_bindings_conflict_after_restart` |
| Normalized Agent name and independent new-key issuance/order | `test_new_keys_share_one_normalized_name_contract_but_not_issuance_or_slot_order` |
| Unsupported markers (including 1), corrupt request and no fallback | `test_unsupported_markers_and_corrupt_bindings_never_fall_back_or_disclose` |
| Current authority, unavailable state, wrong scope/caller and expired delegation | `test_current_authority_and_unavailable_status_remain_distinct_from_new_key_admission`; `test_wrong_scope_caller_and_expired_delegation_deny_lookup` |
| Fixed literal JSON, Unicode edges/length/encoding, case/internal/NFC preservation | `AgentOperationCanonicalizationTest`; existing rotation request tests |
| Invalid cohort markers/capabilities, same-generation checks, stale acknowledgement rejection | `AgentOperationContractTest`, `AgentCohortConformance`, `AgentCohortWriterTest` |
| Safe marker schema | Default generated-schema `AgentDeliveryComponentsTest` |

The reference runner is `InMemoryAgentCanonicalConformanceTest`. It executes actual delivery, revocation, expiry
and cleanup, then reconstructs services over modeled persisted adapters. It is not a real database/process restart
or qualification of consumer concurrency, sink cryptography or activation/use. Existing issuance and delivery suites
retain publication warnings, uncertainty, capacity and recovery proof. No cross-version fixture matrix is needed.
