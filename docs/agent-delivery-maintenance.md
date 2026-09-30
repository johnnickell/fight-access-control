# Agent delivery material maintenance (unreleased v0.5.0 work)

[TASK-00053](../planning/tasks/00053-TASK.md) adds maintenance to the existing
[protected delivery](agent-credential-delivery.md), [retirement](agent-credential-retirement.md) and
[recovery](agent-delivery-recovery.md) paths. The package owns maintenance transitions, expected-state obligations,
read contracts and coordination. Consumers own authorization, actual encryption, keys, database indexes/fences and
protected sinks. This is not a key vault, plaintext retrieval API, physical key-destruction command or supported
partial deployment. Mandatory [cohort guards](agent-operation-cohorts.md) fence maintenance transactions and bind
cleanup authority to the persisted generation. [Package restoration](agent-restoration-safety.md) is implemented;
[real consumer qualification](agent-operation-evidence.md#consumer-gap-register) and release remain separate.

## Public composition

`AgentDeliveryMaintenanceService` exposes three synchronous operations, each taking the original `AgentOperationKey`,
`AgentCredentialDestination` and global `AgentDeliveryId`:

- `rewrap(..., AgentDeliveryKeyVersion $target)` preserves original plaintext, full issuance associated data, delivery
  identity and slot order while replacing only its wrapping representation. It returns no plaintext handle.
- `expire(...)` removes delivery material at original retention expiry without key or sink access, even for obsolete
  slot reservations excluded from delivery discovery. It does not rotate/revoke the authentication credential.
- `cleanup(...)` removes terminal inert sink material, including tombstoning a delivery whose admitted call has not
  arrived yet. It never deletes operation correlation or permits reuse of an old operation key.

Inject `AgentOperationRepository`, `AgentMaintenanceAuthorization`, `AgentDeliveryRewrapper`,
`AgentCredentialCleanup`, trusted `Clock`, shared `TransactionalUnitOfWork` and optional `AgentMaintenancePolicy`.
No production implementation of these consumer capabilities is included. Repository and authorization must
participate on the package transaction's connection; unsupported participation or an outer/nested transaction fails
closed. No new event is required: persisted state is the maintenance outcome, not an issuance/delivery fact.
AuthenticationService, revocation publication and the scoped issuance publication-warning exception are unchanged.

`AgentMaintenanceResult` returns only:

| Value | Meaning |
| --- | --- |
| `rewrapped` | Confirmed expected-state write of a protected replacement copy. |
| `expired` | Confirmed removal at the original retention boundary. |
| `terminal` | Confirmed permanent source-key loss or integrity failure; original correlation remains. |
| `cleaned` | Confirmed sink erasure/tombstone acknowledgement, or an already acknowledged cleanup. Not delivery, activation or use authority. |
| `unchanged` | No applicable transition: same wrapping version, no copy, unexpired material or ineligible cleanup. Not proof of key health or successful delivery. |
| `retryable` | Typed transient key/sink failure or unsupported cleanup capability. State is not acknowledged; unsupported capability requires adapter correction, not blind retries. |
| `rejected` | Authorization, version, exact binding, deadline or expected-state conflict. No existence detail is disclosed. |
| `unavailable` | Unclassified dependency/storage failure; original state remains recoverable unless commit is uncertain. |
| `indeterminate` | Callback completed but commit did not confirm. Neither rollback nor external completion is inferred. |

The existing secret-free operation query reports original issuance, delivery and credential dispositions. It does not
expose wrapping versions, material, provider diagnostics or cleanup admission. `isSinkCleaned()` is persistence state,
not a new public secret/status endpoint. A repeated authorized cleanup resolves its own confirmation.

## Maintenance authority and transaction boundaries

Maintenance authority is **not delivery authority**. A currently authenticated maintainer must have explicit scope,
maintenance Permission/delegation, original target and historical destination access. It may maintain an obsolete
slot reservation or a revoked Agent without gaining permission to deliver or reactivate it. A current owner of a
reassigned slot is not automatically authorized over the predecessor's material. Consumers define those policies;
IDs, actor strings, key versions and scheduler possession are never authority. Routine authorized work needs no
additional human approval or mandatory manual limits configuration.

`AgentMaintenanceAuthorization::authorize()` runs before lookup with null issuance, then again for the exact original
issuance. It holds transaction-duration authority, operation/lifecycle, historical-destination and key-reference
fences and returns an opaque worker/revision epoch plus earliest delegation expiry. Revoke/regrant advances the
epoch; clock rollback must not extend authority. A target key additionally requires explicit rewrap authority and
open write admission. No cached external allow or nested authorization transaction is supported.

Rewrap is one package-owned transaction. The rewrapper runs **inside** this bounded transaction while source/target
key-use and reference fences are held. Unlike sink invocation it has no external credential-delivery effect. Bound
key-provider latency and reject integrations that cannot participate safely; do not call a provider and then pretend
an unfenced write is equivalent. Check authority time again after key work, then compare-and-replace the exact
snapshot. Slow work crossing retention cannot extend it; the write rejects and a later expiry removes the copy.
Rollback preserves the original ciphertext/version and reference count. An uncertain commit returns indeterminate;
retry against authoritative state either observes the target version or performs the still-needed replacement.

Every rewrap preserves canonical request/version, issuance, pinned policy, attempt fence/token, admission, retry time,
receipt and failure history. It advances the operation revision, so a stale admission/completion or rewrap snapshot
cannot restore removed bytes. Delivery may need a fresh attempt after concurrent maintenance; it still uses the
original bytes and delivery ID. Maintenance never makes an old admission authorize a new invocation.

## Cipher representation, failure and key retirement

`AgentDeliveryMaterial` holds `EncryptedCredentialMaterial` and an opaque `AgentDeliveryKeyVersion`-validated label
(1–64 ASCII letters, digits, `_`, `.` or `-`). It is non-serializable and redacts debug output. It is only an internal
persistence/cipher value, never an ordinary message or Application result. Key labels are not provider paths or
credentials. `AgentDeliveryRewrapper` must authenticate the versioned associated data covering **every** field of
`AgentIssuance::toArray()` before re-encrypting identical original bytes. It returns only protected material at the
requested target version. A wrong target version is unavailable and not persisted. The consumer chooses an
authenticated encryption/envelope format and must qualify changed-byte and swapped-tuple rejection; this package
neither supplies cryptography nor claims that a key-label change proves encryption correctness.

Use sanitized `AgentDeliveryFailedException` classifications:

- `TEMPORARY`: retain the original copy/version. This includes target-key unavailability; it is not loss of the source.
- `KEY_RETIRED`: permanent loss of the original source key, not planned removal of a target key. Remove the copy and
  record terminal failure for explicit reconciliation.
- `CORRUPT_MATERIAL`: changed/swapped ciphertext or associated data. Remove the copy and record terminal failure.
- Unclassified faults remain unavailable with rollback, never terminal success or an arbitrary provider message.

Known-active Agents may require a separately authorized new rotation; ambiguous historical provision requires
explicit reconciliation. Never auto-provision/rotate, decrypt the authentication envelope as delivery fallback,
restore a predecessor, revive a revoked Agent or claim enrollment readiness. After acknowledged delivery only
receipt/status recovery exists; later sink loss does not recreate a secret-read facility.

`CountAgentDeliveryKeyReferences` and its handler return a nonnegative **global diagnostic count**, after current
accounting authority both before and after the read. The repository counts every retained delivery copy across
scopes, keyset pages, dispositions, claims, obsolete reservations and unknown canonical versions. It projects only
reference metadata, not ciphertext. Storage failure is unavailable, never zero. A work page or per-scope count is
not a retirement account.

**A zero snapshot never authorizes physical key destruction.** The consumer retirement protocol must:

1. Close the version to **new references** under the shared key-version write-admission fence. All issuance/add,
   rewrap, lifecycle, delivery, maintenance and restore writers use it through commit/rollback. A prepared copy
   cannot commit into a closed version after a stale check. Existing copies may still complete/retry or rewrap away;
   closing admission must not disable recovery/status.
2. Recount authoritatively under that fence and require zero retained copies. Keep admission closed durably across
   restart, and reconcile uncertain writes before claiming zero. Do not discard references or safety evidence to
   meet capacity. Physical retirement is not atomic with a package database transaction.
3. Account separately for leased/in-flight key uses, admitted delivery snapshots and authentication envelopes,
   consumer backups/restoration and any other key users. Do not destroy a key while an authorized key-use lease
   needs it. Source delivery accounting alone says nothing about authentication-envelope dependencies.
4. Execute physical retirement through the consumer's qualified key manager. An interrupted or uncertain physical
   operation must fail closed and be reconciled there; this package exposes no `retireKey()` or `safeToRetire` claim.

## Bounded discovery, retention and cleanup

`ListAgentDeliveryMaintenance` is an immutable Query with canonical `fromArray()`/`toArray()` and named getters.
All canonical fields, including nullable `after`, are required on deserialization. The constructor supplies defaults.
Its handler reads one secret-free page of `AgentOperationView` values, checks scope authority before/after selection
and each target before disclosure, and rejects oversized, duplicate/out-of-order, foreign-scope or unsupported views.
Reads take no claims, commit nothing, dispatch no events and load no material.

- `work = material` selects **all retained copies**, including obsolete reservations. Call `expire()` for expiry-only
  maintenance or `rewrap()` when moving to an authorized target wrapping version; rewrap expires overdue copies before
  key access. Unknown versions cannot be reinterpreted or silently omitted from accounting.
- `work = cleanup` selects `canCleanup(now, policy)`: no retained copy, irreversible terminal delivery/credential state,
  elapsed recovery window and no acknowledged cleanup. A `delivered` operation with a `current` credential is never
  eligible. Pending/retryable state cannot be cleaned merely because material is unexpectedly missing.
- Both selections use exact original scope/historical destination, ascending global delivery ID bytewise, an exclusive
  `after` cursor, then the limit. No current slot-reservation filter is applied: that filter belongs to delivery
  discovery, not maintenance. Database indexes must make selection bounded; do not scan/decrypt all records in PHP.

| Setting | Default | Valid override / behavior |
| --- | --- | --- |
| Maintenance page / work per pass | 50 | 1–100; process at most one selected page per scheduler pass, no refill loop |
| Inert-entry cleanup grace | 86400 seconds | 1–604800 seconds after original delivery retention ends |
| Delivery retention | Existing 86400 seconds | Existing pinned `AgentDeliveryPolicy`: at least lease, at most 604800 seconds |
| Delivery retry / lease / attempts | Existing 30 / 60 seconds / 100 | Unchanged validated delivery-policy bounds |
| New-operation capacity | Existing 100 per scope / 10000 globally | Unchanged provisioning bounds; never gate existing maintenance/status/recovery |

An operation's first delivery claim pins overrides. Before that claim maintenance uses the same default retention
as delivery discovery; a planned but unpersisted consumer override cannot extend a stored copy's lifetime. Rewrap
pins that default if there is no existing policy. Every retention deadline is measured from original issuance,
never from rewrap/restart. Cleanup eligibility is conservative: original retention end plus the configured finite
grace, and terminal state. Grace is a validated worker policy, not a new delivery lifetime; use the same policy for
cleanup selection and execution. Changing it cannot make pending material or current delivered authority eligible.

Schedulers persist the last returned delivery ID even if an item is temporarily unavailable or unchanged, then
continue the next bounded page on a later pass. Reset to null after the final page to include concurrent inserts
before the cursor. This prevents an unchanged early wrapping version or broken key from monopolizing every page.
No package loop implicitly drains an unbounded portfolio. Scheduling cadence is consumer-owned; timely physical
deletion cannot be promised during an outage or absent authorization. Retention nevertheless denies new delivery
admission past its deadline, independently of when cleanup next runs.

## External cleanup, restart and delayed replay

Cleanup first confirms a package transaction authorizing the exact original tuple, then checks its terminal/window
eligibility and authority deadline. It invokes `AgentCredentialCleanup::remove(issuance)` **outside every database
transaction**. No ciphertext or decryption handle crosses this boundary. The sink must atomically remove only that
inert entry and permanently bind a non-recreation tombstone to the delivery ID and full tuple, even if no entry has
arrived yet. Same-ID changed tuple rejects. Repeat of the same cleanup is idempotent. Retain receipt/deduplication
metadata and nondecreasing slot high-water evidence through rebinding; do not erase a slot or its successor.
Unsupported sinks reject before effects, never silently forget history.

After confirmed external completion, a separate package transaction reauthorizes the real maintainer, compares the
same epoch and unexpired admission, checks the original operation revision, and persists `sinkCleaned = true` with
one revision advance. Delivery/credential dispositions, original key/version/request/issuance and receipt/history
remain unchanged. No issuer or future writer may remove the correlation tombstone to reclaim capacity.

Uncertain admission commits never call the sink. A lost sink response or uncertain acknowledgement leaves work
incomplete; a new authorized call repeats the **same tuple**. If acknowledgement actually committed, it returns
cleaned without another sink call. A concurrent authority change, expiration or revision advance rejects stale
acknowledgement. An already-admitted external cleanup cannot be recalled after revocation, but its target was already
irreversibly terminal; it cannot remove a current delivered credential or activate anything. Database and physical
effects are not described as one atomic transaction.

Delay an old stage call through package expiry/retirement and sink cleanup: the durable sink tombstone rejects it,
including identical retries. A lower-order call cannot lower the slot's high-water mark, select or recreate erased
material. Late receipts cannot grant activation/use. Consumers still require confirmed exact delivery and fresh
current package/destination and consumer authority for enrollment and every use. No broker implementation is added.

## Adapter obligations and acceptance evidence

The unreleased repository contract adds `listMaintenance()`, `countDeliveryKeyReferences()` and
`replaceMaintenance()`. Hydrators persist the recorded `sinkCleaned` boolean (false on new operations, never inferred
for old records), state revision and all existing delivery policy/history. Expected-state writes compare the complete authoritative
snapshot, original canonical request/version and issuance; only maintenance successors may be written, with revision
exactly one higher. All writers share lifecycle/authority/key-reference fences. A stale write may not recreate a
missing operation/material, change a canonical key's meaning or reset cleanup/order evidence. No production SQL,
key/sink adapter, physical key retirement, HTTP endpoint or consumer deployment is provided.

Before supporting an adapter, run consumer conformance for: same-connection authority and key-reference fencing;
new reference versus key admission closure; full global counts; rollback/uncertainty; competing rewrap versus
completion/revocation/expiry; authenticated original bytes/binding through actual key changes and restart; sink
cleanup before/after arrival and response loss; changed tuple/bytes, predecessor/successor and cross-scope rebinding;
restoration preserving all tombstones/high-water values; and current activation/use authorization. TASK-00054 owns
reusable integrated conformance; these obligations do not claim a real database/key/sink is already qualified.

Package evidence:

- [Domain tests](../tests/Domain/AccessControl/Agent/AgentMaintenanceTest.php): finite defaults/overrides, canonical
  Query round trips/rejection, expiry and invalid transitions, tombstone/history preservation.
- [Maintenance service tests](../tests/Application/AccessControl/Agent/Security/AgentDeliveryMaintenanceServiceTest.php):
  actual provision/delivery/revocation paths, retry history and original-byte recovery, transient/permanent/corrupt
  outcomes, stale writes, key admission fences, capacity isolation, both outcomes of uncertain commits, outside-
  transaction cleanup, replay, current delivered protection, authorization/time/ABA and safe rollback.
- [Read tests](../tests/Application/AccessControl/Agent/QueryHandler/AgentMaintenanceQueryTest.php): multiple bounded
  pages past obsolete reservations, all-scope accounting including unknown versions, target/scope rechecks, no query
  mutation/material access and safe failure. [Generated schemas](../tests/OpenApi/AgentDeliveryComponentsTest.php)
  compare the opt-in public maintenance components with canonical messages, enums and bounds in the normal suite.

Tests use behavioral in-memory adapters and a deliberately non-production bound cipher. They demonstrate package
contracts and deterministic interleavings, not real cryptography, database concurrency, OS process crashes or
consumer qualification. Nonvisual executable persisted-outcome evidence is appropriate for this library change;
no screenshots or fabricated endpoint are needed. Exact build/coverage evidence and independent-review status belong
to TASK-00053 and its ignored handoff. Implementation is not independent acceptance, merge or release.
