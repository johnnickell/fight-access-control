# Agent credential retirement (v0.5.0)

[TASK-00050](../planning/tasks/archive/00050-TASK.md) implements revocation and the shared predecessor-retirement contract.
It does **not** itself implement recoverable rotation, delivery workers, a protected sink or consumer persistence.
TASK-00051 now supplies protected delivery and TASK-00048 supplies recoverable rotation using this seam. This
composition belongs to v0.5.0. Package restoration is implemented; the [integration guide](agent-integration.md)
identifies real consumer qualification gates and distinguishes them from release certification/publication.
No real database/sink/consumer pass is claimed.

## Public lifecycle and writer inventory

| Entry point | Supported behavior / mandatory boundary |
| --- | --- |
| `AgentCredentialLifecycleService::revoke(actorId, agentId)` | Load exact current Agent, construct its terminal successor, replace through the repository and write enabled audit in one package-owned transaction. Publish `AgentCredentialRevoked` only after commit. |
| Direct `Agent::revoke()` | Construct an immutable successor, preserving the authentication envelope and credential tuple. This alone is not a persisted revocation. |
| Direct `AgentRepository::replace(expected, replacement)` | Compare the entire authoritative predecessor, validate the Domain successor and atomically retire its original delivery before persisting the successor. Never bypass this because the caller is not HTTP or not the lifecycle service. |
| `RotateAgentCredential` / `RotateAgentCredentialHandler` / `Agent::rotateRecoverableCredential()` | Registered void Command coordination with operation correlation and this cancellation seam. Original outcomes use authorized Query dispatch; no direct service/result facade. |
| Rotation-capable direct repository replacement | A valid successor has a different credential ID and exactly the next credential revision. Every predecessor uses the same cancellation boundary. This is not a replacement rotation workflow or authorization to invent operation correlation. |
| `replacePermissionAssignments()` | Must preserve all credential state and operation correlation, and share the Agent credential fence. A stale Permission writer cannot undo revocation. |
| `add()` / consumer hydration | New insertion only, never an upsert over existing authority. Validate persisted authority and current-operation correlation. Reconstructing a revoked Agent as active or newly provisioned is not supported. |
| Authentication nonce consumption | Continue checking exact active credential ID/revision under the existing current-authority fence. Retired credentials cannot authenticate after that fence observes retirement. |

`Agent::canReplaceCredentialWith()` owns successor validity: stable Agent ID, name, creation time, Permission set and
assignment revision and nondecreasing update time. The predecessor must be active. Revocation retains exact credential
ID/revision/envelope; rotation advances revision exactly once with another ID. There is no marker or legacy mode;
see the [current Agent contract](agent-current-contract.md).
No-op replacements, resurrection, revision jumps and unrelated edits reject. A stale/invalid
`replace()` returns false without cancellation or any write. Consumers may not substitute a fresh aggregate built
with `provision()` to reset lifecycle authority.

## Composition and atomic cancellation

Application owns the `TransactionalUnitOfWork`, audit and publication. Consumer repository implementations own
persistence on its **same connection**, not another workflow or transaction. Composition must bind both Agent and
operation repositories to that connection. Every `replace()` requires working operation persistence; no
nullable/no-op fallback is allowed. Missing or inconsistent correlation rejects, never skips cancellation or
fabricates an operation. Every predecessor uses the following indivisible operation:

1. Require the package-owned transaction and hold the exact authoritative Agent/credential fence. Compare all
   predecessor state, including Permission authority. Validate the Domain successor.
2. Call `AgentCredentialOperationRepository::retireCredential(expected, replacement)` on that connection. Resolve exactly one
   original operation by Agent ID, credential ID and credential revision, including delivered/material-free records.
   Missing, ambiguous or mismatched correlation rejects; never manufacture an operation from an envelope or audit.
3. Hold the original operation/delivery, destination binding and shared consumer authority fences through completion.
   Persist `operation->retireCredential(expected, replacement)` against the exact original state revision.
4. Persist the Agent successor. The service then appends audit when configured. Any cancellation, Agent persistence, enabled audit or commit
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

## Optional atomic revocation audit (Unreleased)

Construct the current service with `AgentRepository`, `Clock`, `TransactionalUnitOfWork`, `EventDispatcher`, then
optional last nullable `AuditEvidenceRepository`. For example:

```php
$lifecycle = new AgentCredentialLifecycleService(
    $agentRepository,
    $clock,
    $unitOfWork,
    $eventDispatcher,
    auditEvidenceRepository: $auditEvidenceRepository,
);
$lifecycle->revoke($actorId, $agentId);
```

Omitted/null storage deliberately disables durable audit, not retirement, actor requirements or caller policy.
The direct typed `UserId|AgentId|SystemId|AnonymousId` actor remains required in every composition; it establishes
provenance, never permission. No ambient principal lookup, label conversion or fallback identity is supplied.
This is a pre-v1 constructor-order change: update positional callers directly, without a compatibility overload.

Enabled `agent.credential_revoked` evidence is explicitly inserted after successful full-state replacement and
before commit in the same transaction as Agent authority and repository-owned original-delivery cancellation.
It records the typed Agent, exact actor, trusted `revokedAt`, and real credential ID/revision and ACTIVE → REVOKED
states. Revocation preserves the credential revision; it does not invent a new generic Agent revision. The current
`AgentRepository::replace()` returns only a Boolean and exposes neither the resolved original operation key/delivery
nor its state revision. Those audit fields remain null; the service does not guess them from actor/target IDs,
inspect an Event or add material access merely for audit. Repository correlation/fencing remains mandatory.
Evidence uses a fresh identity, not a universal command-deduplication token, and contains no envelope, secret,
raw request or diagnostic text. Evidence access and retention still need consumer protection.

Configured audit failure aborts all staged authority/cancellation/evidence writes. Already revoked, missing,
wrong-identity and stale targets retain their refusals and create no committed evidence; repeated invocation is
not a no-change mutation record. Uncertain commit acknowledgement still throws without proving rollback.
Confirmed evidence survives ordinary subscriber/publication failure, including both publishers failing, while the
exact primary fault still throws. There is no warning-return conversion or later audit backfill.

Use `AgentCredentialLifecycleServiceTest` for omitted/null/enabled composition, all four typed actor kinds,
exact persisted facts and both actual uncertain outcomes. `AgentCredentialRetirementTest`, revocation contract tests
and delivery/lifecycle conformance retain rollback, competing-writer, cohort/restoration and post-commit coverage.
Package in-memory transactions do not qualify consumer storage: bind audit and both repositories to the actual
shared connection/transaction and prove mapping, locks, rollback and restart there before opting in.

## Expected-state transitions and authority fences

Claim and admission details below are implemented by TASK-00051's delivery service; TASK-00052 implements
scheduler discovery. They remain
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

The unreleased [worker failure boundaries](agent-integration.md#worker-failures) classify authoritative absence as Common Application
`ResourceNotFound`, and already-retired state or lost expected-state replacement as `StateConflict`. A wrong returned
Agent identity remains internal and cannot reach retirement. Consumer entry authorization is unchanged.

Revocation keeps existing failure behavior: pre-commit errors roll back and rethrow; confirmed commit precedes the
revocation event; a success-publication fault still throws that original fault after attempted redacted failure
publication. Failure-publication faults cannot replace it. Both publishers failing leaves committed revocation and
retirement intact. Provision/rotation instead contain publisher faults after confirmed commit under their void-Command contract;
that exception does not apply here. An unconfirmed commit remains
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

Use the [Agent consumer checks](agent-integration.md#restoration-and-consumer-evidence-handoff) to qualify
actual database and authority writers, in-flight sink effects, activation/use and restoration. Cover claim,
admission and completion, including direct repository paths and revoke/regrant of authority. Revocation plus
cancellation must not depend on a generator, cipher or sink. Package fixtures do not qualify those adapters.
