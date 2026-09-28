# Agent credential retirement (unreleased v0.5.0 work)

[TASK-00050](../planning/tasks/00050-TASK.md) implements revocation and the shared predecessor-retirement contract.
It does **not** implement recoverable rotation, delivery workers, a protected sink or consumer persistence. This
intermediate composition remains unreleased and not deployable until the downstream delivery, rotation and
compatibility slices are accepted. No real database/sink/consumer qualification is claimed.

## Public lifecycle and writer inventory

| Entry point | Supported behavior / mandatory boundary |
| --- | --- |
| `AgentCredentialLifecycleService::revoke(actorId, agentId)` | Load exact current Agent, construct its terminal successor, replace through the repository and write safe audit in one package-owned transaction. Publish `AgentCredentialRevoked` only after commit. |
| Direct `Agent::revoke()` | Construct an immutable successor, preserving the recoverable-operation marker and authentication envelope. This alone is not a persisted revocation. |
| Direct `AgentRepository::replace(expected, replacement)` | Compare the entire authoritative predecessor, validate the Domain successor and atomically retire its original delivery before persisting the successor. Never bypass this because the caller is not HTTP or not the lifecycle service. |
| `AgentCredentialLifecycleService::rotate()` / `Agent::rotateCredential()` | Existing legacy-only behavior remains. Recoverable Agents still reject the raw-return path. TASK-00048 owns its replacement/removal; this TASK supplies the cancellation contract it must use. |
| Rotation-capable direct repository replacement | A valid successor has a different credential ID and exactly the next credential revision. For a recoverable predecessor it uses the same cancellation boundary. This is not a replacement rotation workflow or authorization to invent operation correlation. |
| `replacePermissionAssignments()` | Must preserve all credential state and the recoverable marker, and share the Agent credential fence. A stale Permission writer cannot undo revocation. |
| `add()` / consumer hydration | New insertion only, never an upsert over existing authority. Persist and hydrate the marker faithfully. Reconstructing a revoked Agent as active or newly provisioned is not supported. |
| Authentication nonce consumption | Continue checking exact active credential ID/revision under the existing current-authority fence. Retired credentials cannot authenticate after that fence observes retirement. |

`Agent::canReplaceCredentialWith()` owns successor validity: stable Agent ID, name, creation time, Permission set and
assignment revision, nondecreasing update time, and unchanged recoverable marker. The predecessor must be active.
Revocation retains exact credential ID/revision/envelope; rotation advances revision exactly once with another ID.
No-op replacements, resurrection, revision jumps, marker downgrades and unrelated edits reject. A stale/invalid
`replace()` returns false without cancellation or any write. Consumers may not substitute a fresh aggregate built
with `provision()` to reset lifecycle authority.

## Composition and atomic cancellation

Application owns the `TransactionalUnitOfWork`, audit and publication. Consumer repository implementations own
persistence on its **same connection**, not another workflow or transaction. Composition must bind both Agent and
operation repositories to that connection. A recoverable `replace()` requires working operation persistence; no
nullable/no-op compatibility fallback is allowed.

The repository write performs the following indivisible operation:

1. Require the package-owned transaction and hold the exact authoritative Agent/credential fence. Compare all
   predecessor state, including Permission authority and the recoverable marker. Validate the Domain successor.
2. Call `AgentOperationRepository::retireCredential(expected, replacement)` on that connection. Resolve exactly one
   original operation by Agent ID, credential ID and credential revision, including delivered/material-free records.
   Missing, ambiguous or mismatched correlation rejects; never manufacture an operation from an envelope or audit.
3. Hold the original operation/delivery, destination binding and shared consumer authority fences through completion.
   Persist `operation->retireCredential(expected, replacement)` against the exact original state revision.
4. Persist the Agent successor. The service then appends audit. Any cancellation, Agent persistence, audit or commit
   rollback restores **all** writes, including the delivery copy and revision. No separately committed cancellation
   or partial Agent retirement may survive a rollback.
5. Commit once, then publish. A direct consumer coordinator also owns its full audit transaction; it must use this
   same repository boundary, never recreate cancellation policy itself.

No outer/nested transaction, subscriber, asynchronous cancellation, key lookup, decryption or sink invocation can
substitute for these steps. Repositories reject unsupported participation with sanitized
`AgentOperationRejectedException`; adapter exceptions must omit provider details, material, chained exceptions and
sensitive trace arguments. Mark **both Agent arguments** `#[SensitiveParameter]` on every concrete `replace()` and
`retireCredential()` implementation and any forwarding method. Interface parameter attributes are **not inherited**;
redacting only the inner cancellation method leaves the outer replacement frame's authentication envelopes exposed.
Also mark the concrete `TransactionalUnitOfWork::commitTransactional()` callback parameter `#[SensitiveParameter]`:
a closure's bound service or captured state can otherwise expose the same material in the outer transaction frame.
This is a required consumer composition obligation even though the upstream Common interface does not annotate it.
Qualify exception traces and structured debug output with argument capture enabled (`zend.exception_ignore_args=0`),
not just exception strings. A nonconforming adapter cannot be made safe by this document: qualify its actual
transaction and write fences before adoption.
TASK-00056 owns complete cohort/version compatibility and exclusion of old binaries.

The operation's Domain transition preserves its original key, canonical version/request, globally unique delivery ID,
Agent/credential tuple, destination binding/write order and issuance time. It removes the delivery copy, advances
`getStateRevision()` and records `REVOKED` or `SUPERSEDED` for the **original** credential. It never substitutes a
successor's secret or metadata. Already-retired credential transitions reject; stale whole-record writes lose.
`retireMaterial()` also advances the revision, so cleanup cannot leave old delivery snapshots writable.

## Expected-state transitions and authority fences

Claim and admission details below are requirements for downstream writers, not an implemented worker. They remain
separate from safe `AgentDeliveryDisposition`: pending/claimed/admitted work has not yet confirmed delivery.

| State before retirement | Atomic persisted result | Old worker consequence |
| --- | --- | --- |
| Pending or retryable | Delivery `RETIRED`, credential retired, no material, next state revision | Cannot claim/admit/materialize from the old snapshot. |
| Claimed, not admitted | Same retirement; claim invalidated by the revision | A claim reserves work only and grants no material access. Admission loses. |
| Admitted, including external call in flight | Same retirement; admission invalidated for completion | No recall of previously disclosed bytes. Late effects may stage only inert bytes at the fixed destination; no stale acknowledgement or activation. |
| Delivered | Keep `DELIVERED` as historical fact; retire credential, remove any residual delivery copy, advance revision | A receipt does not grant continued activation/use. No redelivery. |
| Expired, terminal failure or material-retired | Preserve that terminal delivery history; retire credential and advance revision | Cannot restore material, reuse the operation key or reopen delivery. |
| Already retired credential / stale predecessor | Reject with no mutation | Cannot revive or reinterpret original issuance as a successor. |

`hasPendingDeliveryAtRevision()` checks only an exact expected-state invariant: revision, current original credential,
non-null material and pending/retryable disposition. It is **not** authorization, a claim, admission or permission to
invoke a sink. Writers must additionally check authoritative Agent state, current destination binding/order, exact
claim/admission identity, trusted deadlines and consumer authority epochs under the same fences. Every persisted
claim/admission/outcome replacement advances the operation revision; never reset it or restore an old snapshot.

All consumer caller/delegation/Permission and destination writers participate in those fences. Revocation and regrant
advance a monotonic epoch even if the apparent allow or binding returns to its former value. Old claimants cannot
acknowledge with a pre-change epoch. A newly authorized worker may reconcile an exact receipt only under fresh valid
claim/admission and current credential/destination authority; it cannot revive a retired operation. Consumer
activation and each use still require their own fresh authorization, not an issuance result or once-valid admission.

## Caller policy, publication and failure behavior

Consumer policy protects every lifecycle entry point, including direct calls. `actorId` supplies audit identity, not
caller authority or a delivery capability. Cancellation does not require the originating caller's still-valid grant:
a currently authorized maintainer must be able to revoke even after that grant or destination access has gone away.
It still participates in the shared fences; it never materializes secrets.

Revocation keeps existing failure behavior: pre-commit errors roll back and rethrow; confirmed commit precedes the
revocation event; a success-publication fault still throws that original fault after attempted redacted failure
publication. Failure-publication faults cannot replace it. Both publishers failing leaves committed revocation and
retirement intact. There is **no** provision/rotation publication-warning result here. An unconfirmed commit remains
an error, not evidence of rollback; inspect current authoritative state before deciding the next authorized action.
No automatic new issuance or compensating restore is allowed.

## Bounds and retention relationships

Retirement targets one exact indexed original credential operation: no scan/batch worker, secret generation, sink
retry or new-work reservation. Its default and invariant are **immediate removal** of the delivery copy in the
retirement transaction; there is no grace override that can preserve deliverability of revoked authority.

[Provisioning limits](agent-provisioning-operations.md#finite-bounds) remain 512 name bytes, 100 pending operations per
scope and 10000 globally, with the documented validated overrides. Retirement never calls new-work capacity admission,
so default-only operation and valid lower overrides cannot block it. Retiring material releases pending-copy capacity,
not correlation or deduplication evidence. Same-key resolution still returns original issuance, never a second Agent.
Slot high-water/write-order and secret-free correlation are retained permanently, including after reassignment or
material cleanup. An old key never becomes reusable. The authentication envelope is independent and is never a
recovery fallback. TASK-00051/00053 own worker duration/retention overrides and bounded maintenance; they cannot
weaken immediate lifecycle retirement or erase replay evidence. No manual limit setup or extra human approval is
required to revoke or recover routine work.

## Package evidence and remaining qualification

`AgentCredentialRetirementTest` exercises real provisioning, Domain retirement, the lifecycle service, status and
same-key resolution through transaction-aware in-memory repository doubles. `ControlledAgentDeliveryWriter` supplies
only controlled expected-state claim/admission/completion writes. It is not a worker, does not invoke a sink and
makes no real database concurrency, receipt, deadline or activation/use claim.

| TICKET-00013 evidence | Focused tests / boundary |
| --- | --- |
| D2 / D5 pre-admission and stale completion fences | `test_retirement_fences_controlled_claim_admission_and_completion` covers pending, claimed and admitted snapshots through service and direct repository paths. |
| D2 consumer epochs / shared fence | `test_consumer_authority_writers_share_retirement_fences_and_aba_invalidates_old_snapshots` demonstrates required consumer-writer participation with controlled caller/destination ABA. |
| D5 direct rotation-capable write | `test_direct_rotation_capable_write_uses_the_same_cancellation_without_building_rotation` uses a hydrated successor, not TASK-00048's future workflow. |
| D5 / D6 atomicity and no key/sink dependency | Successful revocation plus cancellation/Agent/audit/commit rollback cases assert persisted outcomes; generator and cipher are never called. Missing correlation, wrong connection, absent/nested transaction reject. |
| D5 publication | Both publishers fail; committed revocation remains and the original publication fault rethrows. |
| D6 safe retained original identity | Domain tuple checks, all delivery dispositions, same-key resolution and secret-free status/debug/audit assertions; no successor-secret or authentication-envelope fallback. `test_retirement_failure_debug_redacts_agents_with_exception_arguments_enabled` proves redacted replacement trace arguments and safe structured failure debug for missing/ambiguous correlation, cancellation storage failure and unsupported participation, with rollback preserved. |
| D7 defaults/overrides and cleanup/replay | Default-only retirement and one-slot capacity exhaustion/cleanup tests preserve original resolution and monotonic destination order. |

TASK-00051 must prove actual admission and in-flight sink races, TASK-00048 actual replacement rotation, TASK-00053
maintenance, and TASK-00054 reusable all-path delivery/lifecycle conformance. Real consumer database, authority-writer,
sink, activation/use and migration qualification remain mandatory before adoption. There is no HTTP/UI change;
executable persisted-state tests are the useful before/after evidence, not screenshots.
