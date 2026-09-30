# Agent operation canonical upgrades (unreleased v0.5.0)

[TASK-00057](../planning/tasks/00057-TASK.md) preserves original key meaning through a canonical normalization
upgrade. It extends the existing provision/rotation request values and [cohort contract](agent-operation-cohorts.md),
not a pluggable canonicalizer registry or a migration framework. This is package behavior, not release, migration,
real database qualification, enrollment activation or credential-use authority.

## Supported representations

`AgentOperationCanonicalization` owns frozen readers for versions **1 and 2**. Do not delegate historical equality
to today's mutable `AgentName` policy. Both versions use UTF-8 input and a nonempty normalized name of at most 120
Unicode code points. Request construction still bounds original UTF-8 input to 4096 bytes; new-work admission retains
its existing default 512-byte limit and optional validated override. A retained retry bypasses new-work capacity and
raw-name admission limits, not authentication or the original version's name validity.

| Version | Provision name semantics | Creation admission |
| --- | --- | --- |
| `1` | Trim exactly ASCII space, tab, LF, CR, NUL and vertical tab at the edges. Preserve case, internal whitespace, form feed and Unicode whitespace. | Existing persisted v1 cohorts continue unchanged. |
| `2` | Trim the v1 edge bytes plus the fixed Unicode White_Space set: U+0009–000D, U+0020, U+0085, U+00A0, U+1680, U+2000–200A, U+2028, U+2029, U+202F, U+205F and U+3000. Preserve case and internal whitespace; do not trim U+200B/U+FEFF. | Only a qualified persisted cohort explicitly selecting `creationVersion: 2` creates new v2 keys. |

Version two supports operator names pasted with Unicode edge whitespace. Its exact set is frozen, not a dependency
on a changing Unicode property database. It performs no case folding, internal-whitespace collapse or Unicode NFC
conversion. A v2 provision stores the same normalized name on the new Agent; existing Agents are not renamed.
The opt-in `Fight.AccessControl.AgentOperation.Confirmed` OpenAPI component accepts canonical versions `1` and `2`,
matching readable safe Views; unknown versions remain excluded. This expands the unreleased v0.5.0 schema without
changing its fields, reference names or consumer-owned transport.
No runtime call switches a cohort or rewrites a historical binding automatically. There is no extra routine approval
or configuration step: the normal deployment cohort selection owns the upgrade.

Persist the version **and the exact canonical JSON string**, not just a digest:

- Provision: `["provision", normalizedName, destinationId, destinationRevision]`.
- Rotation: `["rotate", agentId, originalExpectedCredentialId, originalExpectedRevision, destinationId, destinationRevision]`.
- Encoding is PHP `json_encode(..., JSON_THROW_ON_ERROR)` with no other flags; preserve element order, integer types
  and escaped bytes. Identity strings use the existing value objects' canonical representations.
- Rotation's representation is identical across v1/v2: version two changes only provisioning-name normalization.
  Keep the original predecessor forever; retries never substitute the current successor or reapply the predecessor
  mutation precondition. Kind, target, predecessor and destination remain bound in both versions.
- There are currently no additional outcome-affecting request options. Operational limits and delivery/maintenance
  policies are not additional issuance inputs. A future outcome-affecting option requires an explicit version design,
  not silent omission or reinterpretation of retained keys.

For example, a v1 key created with NBSP + `Runner` + ideographic space is **not** equal to `Runner`, even after a
v2 creation switch. A v2 key treats those names as equal even after switching creation back to v1 with v2 readers
retained. ASCII-edge equivalence remains unchanged for either version.

## Lookup, authority and retained lifetime

Both issuance services first hold the compatible cohort fence and obtain current trusted scope/destination (and
rotation target) authorization. Then they look up the original scoped key, including permanent tombstones. Only
an authoritative absent result selects `getCreationVersion()` and new-work admission. Existing records use their
own version through `AgentCredentialOperation::resolve()`, without touching `AgentName`, generating secrets,
rewriting bindings, repeating audit facts or publishing another issuance event. The service transaction still runs;
resolution is not a promise of zero transaction activity.

[Status reads](agent-operation-status.md) authorize before lookup and again with the original target before checking
recorded version/binding. They neither open a transaction nor select creation rules. Installed historical readers
remain usable for safe reads even when the writer cohort is incompatible; a readable history never authorizes
new-key creation, delivery, activation or use. Consumer current-authority checks apply to originators and delegated
workers alike; scope/caller mismatch, revoked authority or expired delegation must deny before disclosure.

Unknown/corrupt version numbers or absent package reader support return sanitized `UNSUPPORTED_VERSION`, without
fallback to a current reader, key reuse or new issuance. A malformed or nonmatching retained binding conflicts.
Storage failure remains unavailable/indeterminate, never absence. No raw secret, ciphertext, provider error, key
path or secret-read capability belongs in results or failures. Confirmed issuance stays distinct from delivery,
enrollment activation and launch/use permission.

Completed, expired, revoked, superseded and cleaned operations preserve the exact key, version, canonical request,
original issuance and lifecycle history. Cleanup removes only eligible material/inert external copies; it does not
delete correlation. **Reader lifetime follows permanent key/tombstone retention, not ciphertext retention.** This
release removes neither reader. A later package that cannot interpret any retained version is incompatible; it must
not rewrite old bindings under another normalizer or manufacture replacement keys.

## Cohort and deployment obligations

- Storage/destination contract versions remain `1`. Destination registration identity/revision, ownership fences,
  cross-scope reservation order, sink tuple, global delivery ID and high-water meaning do not change.
- `readerVersions` describes all retained-reader obligations, not a feature toggle that disables an installed reader.
  It must include `1`, the selected creation version and every persisted version, including tombstones. This binary
  implements only `1` and `2`; unknown requirements or omission of the creation reader deny writer admission.
- Both repositories must agree on creation version, reader-obligation set and generation. Array ordering/duplicate
  entries do not alter that set. Equal generations with different creation/reader settings reject.
- The consumer must inventory retained versions and qualify the actual installed readers. After v2 issuance,
  switching creation back to v1 still requires `[1, 2]` and a new generation. The snapshot cannot discover omitted
  storage records or stop an unchecked old binary: consumers must retain obligations and fence old binaries at
  storage/trusted admission. Old code which cannot satisfy the v2 obligation must not restart as a writer.
- Follow the existing exclusive cohort switch procedure, preserving pending admissions, original leases, receipts,
  authority epochs and ordering evidence. A switch invalidates old generation-bound acknowledgements, not disclosed
  bytes. Compatible recovery uses the original delivery copy; there is no authentication-envelope fallback.
- Do not convert a consumer database, rewrite historical bindings or roll back external effects through these APIs.
  Restoration/reconciliation remains [TASK-00058](../planning/tasks/00058-TASK.md); actual rollout and restore rehearsals
  remain mandatory consumer evidence.

## M3 executable evidence

The consumer-bindable [AgentCanonicalUpgradeConformance](../tests/Application/AccessControl/Agent/Security/AgentCanonicalUpgradeConformance.php)
uses `DeliveryConformanceFixture & AgentCanonicalUpgradeFixture` and real public package services. Bind the fixture's
ports to actual adapters; change real persisted cohort settings under the conflicting fence, expire delegation
through its authority writer, and inject corrupt versions without rewriting other state. Restart must discard process
composition while retaining durable storage/sink/authority. Independently load original records, counters, audits,
Agent state and sink order; do not return canned service outcomes.

| Requirement | Executable scenario |
| --- | --- |
| Persisted provision/rotation, changed creation rules and fresh service composition | `test_restart_and_creation_switch_preserve_original_keys_in_every_retained_state`: both versions and six states (pending, delivered, revoked, superseded, expired, cleaned); original identities/requests/Agent state retained, no new generation/audit/fact/material/sink work |
| Historical conflict/equivalence across changed normalization | Same retained-state test plus `test_historical_conflicts_cannot_become_equal_under_new_normalization`: name, kind, target, predecessor identity/revision, destination identity/revision |
| Genuinely unseen key, version-specific Agent name, unchanged destination order | `test_only_unseen_keys_use_new_creation_rules_and_normalized_agent_name` |
| Unknown/corrupt/unsupported version and malformed binding | `test_corrupt_or_missing_historical_versions_never_fall_back_or_disclose` |
| Historical readers versus incompatible creators/restarts | `test_reader_support_does_not_admit_an_incompatible_restarted_creator`; `AgentOperationContractTest` also rejects disagreeing repository creation/reader settings |
| Current authority, unavailable state, wrong scope/caller and expired delegation | `test_current_authority_and_unavailable_status_remain_distinct_from_new_key_admission`; `test_wrong_scope_caller_and_expired_delegation_deny_cross_upgrade_lookup` |
| Frozen v1 JSON bytes, v2 edge code points and unchanged internal/case semantics | `AgentOperationCanonicalizationTest`; existing rotation request, status, issuance and delivery suites remain in the default gate |

The reference runner is `InMemoryAgentCanonicalUpgradeConformanceTest`. Its v1 fixtures are created by the current
package's frozen v1 writer, with independently asserted literal v1 bytes in Domain tests; this is not a run of an old
binary. Restart reconstructs service composition over modeled persisted adapters, not a database/process restart.
The scenarios execute actual package delivery, revocation, expiry and cleanup rather than hand-authoring terminal
states. They do not qualify real consumer locking, schema conversion, key retention, old-binary exclusion, external
sink cryptography or activation/use authorization. Run those separately before adopting the upgrade.
