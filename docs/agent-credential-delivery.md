# Protected Agent credential delivery (unreleased v0.5.0 work)

[TASK-00051](../planning/tasks/00051-TASK.md) implements one complete protected delivery attempt for an operation
prepared by [provisioning](agent-provisioning-operations.md) or
[TASK-00048 rotation](agent-rotation-operations.md). It uses [atomic retirement](agent-credential-retirement.md)
rather than a parallel lifecycle writer. This is package Domain/Application behavior with behavioral in-memory
composition proof, **not a supported deployable consumer integration**. TASK-00052 now adds
[discovery and receipt-first restart recovery](agent-delivery-recovery.md); TASK-00053 adds
[material maintenance](agent-delivery-maintenance.md). Real consumer qualification and restoration remain downstream. No production adapter is supplied.

## Public composition

Call `AgentCredentialDeliveryService::deliver(AgentOperationKey, AgentCredentialDestination, AgentDeliveryId)` as
the authenticated worker. The input is exact original correlation, not an arbitrary slot, URL, path or secret handle.
It contains no actor string. Inject:

- `AgentRepository`, `AgentOperationRepository` and the package-owned `TransactionalUnitOfWork` on the same connection.
- `AgentOperationAuthorization` for current worker/scope/delegation/destination authorization **before every key
  lookup**. Its audit identity return is not a substitute for authorization and is not exposed by delivery.
- `AgentDeliveryAuthorization` for current target/Permission/delegation, destination owner/binding and reserved slot
  write order. It returns `AgentDeliveryAuthority`: a bounded safe opaque epoch and earliest authorization expiry.
- `AgentDeliveryDecipher` for authenticated decryption of the **delivery copy only**. The existing
  `AgentDeliveryCipher` continues to own preparation; an implementation may implement both interfaces.
- `AgentCredentialSink` for capability validation, immutable staging and independent exact durable receipt verification.
- A trusted `Clock` and optional validated `AgentDeliveryPolicy`. Defaults need no manual configuration or additional
  human approval. Current authorization is still mandatory.

These are consumer-implemented public capabilities, not generic secret-read interfaces. Frameworks, scheduling,
policy, cryptography, persistence and sink choice remain consumer-owned. Never expose the decipher/invocation or
repository behind a caller-selected secret endpoint. Unsupported composition must fail closed.

`AgentDeliveryResult` is a backed enum with safe values only:

| Result | Meaning |
| --- | --- |
| `delivered` | Confirmed persisted acknowledgement, or recorded delivery with its receipt reverified; not activation/use authority. |
| `reconciliation_required` | Recorded delivery has no verifiable retained sink entry/receipt; no new materialization or automatic rotation. |
| `retryable` | Confirmed transient outcome retaining bounded original material and a retry time. |
| `terminal` / `expired` | Confirmed terminal failure or retention expiry; delivery material removed, original correlation retained. |
| `retired` | Recorded retired material under otherwise current credential/authorization; revoked credentials reject the delivery path. |
| `deferred` | Current claim or retry delay prevents another attempt; no new invocation. |
| `rejected` | Authorization, correlation, version, credential, stale claim/admission or deadline rejected. No existence details. |
| `unavailable` | A stage could not complete, or required transactional participation was unavailable. No dependency diagnostics. |
| `indeterminate` | The transaction callback completed but commit did not confirm. Neither rollback nor delivery success is inferred. |

Use the existing [authorized status query](agent-operation-status.md) for a secret-free durable snapshot. A result
never returns a credential, ciphertext, key version/path, receipt, claim token, exception or decryption handle.
The original TASK-00051 slice introduces no DTOs or optional delivery event. TASK-00052 adds the bounded discovery
Query; confirmed operation state remains the fact, not event delivery. The issuance/rotation publication-warning
exception is not extended.

## Three independent transactions

1. **Claim.** Authorize scope before lookup; validate exact operation key, destination, delivery ID and supported
   canonical version; read the current original Agent credential; obtain current target/slot authorization. Persist
   a new opaque `AgentDeliveryClaimId`, monotonic attempt fence, bounded lease, pinned policy and state revision.
   An active lease or future retry time defers. A claim reserves work and grants **no materialization authority**.
   TASK-00052 may instead confirm a current persisted admission for receipt-only reconciliation, never invocation.
2. **Admission.** Re-read and reauthorize in a new short transaction. Domain validates the exact claim and expected
   operation revision, current credential and unfinished material. Persist authorizing epochs and the earliest of
   claim lease, admission duration, authorization expiry and original delivery retention. Confirming this commit
   linearizes permission to materialize. A callback that completed without confirmed commit never permits decryption,
   even if the underlying admission actually committed.
3. **Outside every database transaction**, check the admitted deadline after commit, check sink support, and use
   optional `AgentCredentialReceiptLookup` before unnecessary decryption. A recovered admission only reconciles a
   found receipt; absence defers. For fresh admission with no receipt, recheck before materialization,
   authenticate/decrypt the bound delivery copy, validate the invocation's original tuple,
   and check the deadline again before `stage()`. Destroy the local invocation reference after staging. Verify the
   opaque receipt against the exact original issuance tuple without another secret read.
4. **Outcome.** In a third transaction, reauthorize scope and target/slot, read current credential, and validate exact
   operation revision, claim fence/token, lease, admission epoch/deadline and current authority. Persist receipt and
   `delivered` only after successful exact verification. Remove only delivery material. A typed temporary failure
   persists `retryable`, original material/version and next retry time; permanent failures remove the delivery copy.

All state transitions live in `AgentCredentialOperation`; `AgentDeliveryAttempt` owns claim/admission comparisons
and deadline caps. Application coordinates ports and transactions rather than reconstructing those rules.

`AgentOperationRepository::replaceDelivery(expected, replacement, expectedAgent)` is an atomic expected-state
write, not a save/upsert. It verifies the authoritative expected operation revision and original tuple, expected
Agent identity/credential/state and successor revision, and holds all fences through commit. It cannot resurrect
retired work or accept a different connection. Every direct consumer delivery write has the same obligations as
this service. Repository methods must never commit independently. Consumer authority writers, slot reservation and
reassignment, claims, admission, acknowledgement, retirement and cleanup share these fences. A read followed by an
unfenced write is not compliant. Concrete SQL/lock design and real concurrency qualification are consumer-owned.

The authority epoch must bind the **real worker**, scope/delegation, Permission/target authority, destination
ownership/binding and all relevant revisions. Revoke/regrant ABA advances it irreversibly; a restored allow must
not recreate an old epoch. Authorization executes on the shared transaction connection, never as a cached external
allow or a nested transaction. The clock must be trusted, consistent with authorization expiry and suitable for
lease decisions; wall-clock rollback must not extend authority. Consumer integration must enforce that time contract.
A successful callback is not a successful commit. The Unit of Work must commit synchronously, reject nested outer
transactions, roll back callback failures and expose commit failures rather than silently returning success.

Persist the operation's canonical binding, issuance, state revision, material, dispositions, attempt fence/token,
lease, authority epoch/expiry, admitted deadline, retry time, verified receipt and closed failure classification.
Persist the first-claim policy via `AgentDeliveryPolicy::toArray()` and reconstruct it using validated constructor
arguments. Later configuration cannot extend that operation's policy. Preserve attempt history/receipt on lifecycle
retirement. Unknown canonical versions reject without reinterpreting correlation.

## Protected sink and cipher obligations

`AgentDeliveryDecipher::materialize(material, issuance)` authenticates versioned associated data covering **every
field of `AgentIssuance::toArray()`** before returning `AgentCredentialInvocation`. Swapped/corrupt ciphertext
produces `CORRUPT_MATERIAL`; unavailable keys produce `TEMPORARY` or `KEY_RETIRED` as appropriate. Never decrypt the
Agent authentication envelope as fallback. The invocation contains only the fixed tuple and original secret;
`revealSecret()` is for the sink implementation, not an Application result. Serialization is prohibited and debug
output is redacted. Do not cache, log or retain invocation objects in production; PHP does not guarantee memory erasure.

`AgentCredentialSink` requires all three methods:

- `assertSupported(issuance)` rejects unsupported fixed-slot staging/ordering/receipt capability **before decryption**.
- `stage(invocation)` atomically stages an immutable versioned entry. **Delivery ID alone** is the idempotency key,
  across retries, scope collisions, takeover and key rewrapping. Bind it to original bytes and the full original tuple.
  Different bytes/tuple reject without a write. Retain deduplication and nondecreasing per-slot high-water evidence
  through reassignment and secret cleanup. Equal order with another binding rejects; delayed lower-order writes
  cannot overwrite or select newer material. An identical retry may return its original receipt without changing
  selection, including after a higher-order write. Removed material cannot be silently recreated by replay.
- `verify(receipt, issuance)` independently checks the durable binding/entry. A format-valid or merely echoed receipt
  is not evidence. Missing, swapped, unverifiable or lost material cannot pass. The opaque receipt has 16–128 ASCII
  letters, digits, `_` or `-`; it must contain no digest, provider path or secret-read capability. Verification reads
  receipt metadata, not credential bytes.

Stage and verification run outside all package transactions. Throw only sanitized `AgentDeliveryFailedException`
classifications (`TEMPORARY`, `CORRUPT_MATERIAL`, `KEY_RETIRED`, `UNSUPPORTED_SINK`, `INVALID_RECEIPT`) without previous
exceptions. Unknown throwables become bounded temporary state, never guessed success. Service results expose none
of the throwable, trace or provider message. Concrete sink/decipher/repository implementations must retain
`SensitiveParameter` annotations; interface attributes are not inherited. Unit of Work callbacks must also be
sensitive because captured services can reach credential storage. Do not log caught dependency exceptions.

### Late effects and consumer selection

Authority revocation/reassignment/retirement **before admission** denies without decryption. After admission, the
already-admitted call may stage fixed-slot bytes even if authority changes before materialization or during the
call. Expiry during a call cannot recall bytes. Late acknowledgement still fails current authority, epoch, revision
and deadline checks. Staged bytes must therefore remain **inert**, including before the sink learns a new package
credential or slot order.

Consumer selection requires an exact match to **current** package credential and destination tuple, not latest arrival
or a sink pointer. Confirmed delivery plus fresh consumer enrollment authorization is required for activation; each
broker/use operation independently checks current package and consumer use authority. A receipt, issuance or old
admission never grants launch/signing permission. Different-slot replacement and slot reassignment across Agents/scopes
must preserve this rule. No activation/broker implementation is added here.

## Finite bounds and failure recovery

| Setting | Default | Valid override |
| --- | --- | --- |
| Claim lease | 60 seconds | 1–3600 seconds |
| Admission duration | 15 seconds | 1 second through claim lease |
| Retry delay | 30 seconds | 1 second through retention |
| Delivery retention | 86400 seconds from **original issuance** | At least claim lease, at most 604800 seconds |
| Maximum attempts | 100 | 1–1000 |

Every claim pins a policy if not already pinned. Admission is always capped by the earliest deadline, including
retention and authorization; a late-returning admission commit cannot start decryption/invocation. Retry must wait
for **both** the previous claim lease and retry time. A fresh claim advances its attempt fence and uses a fresh
opaque token but never changes delivery ID, original bytes, operation key or slot write order. At maximum attempts
or retention expiry, the next authorized attempt records terminal/expired and removes delivery material. A worker
cannot materialize past retention even if no cleanup worker has yet removed the stored copy. TASK-00053 supplies
[bounded maintenance/cleanup](agent-delivery-maintenance.md) when no delivery attempt runs; neither path promises
physical deletion while scheduling, authorization or storage is unavailable.

New-operation capacity (default 100 pending per scope / 10000 globally, with provisioning overrides) is not consulted
by claim/admission/outcome or status. Recovery uses existing reservations and consumes no new issuance capacity.
Retry counters are bounded; no configuration can disable the lease, deadline, retention or attempt limits.

Lost sink response, failed outcome persistence, expired admission and takeover can repeat staging with the same
original identity/bytes. This is **at-least-once**, not exactly-once secret disclosure. An uncertain claim/admission
returns without external effects; an uncertain outcome returns indeterminate even when delivered was actually
persisted. A later authorized attempt reads recorded completion without rematerialization, or takes a fresh claim
once due and verifies the same stable sink receipt. TASK-00052 implements
[bounded scheduler discovery and receipt-first recovery](agent-delivery-recovery.md), including still-current
admission reconciliation without invocation. After completion, no package path rematerializes that delivery copy;
receipt verification reports `reconciliation_required` if the recorded receipt or sink material is lost.
Sink loss after completion
requires fail-closed reconciliation, not an automatic reissue, rollback to a predecessor or receipt-based activation.

## Evidence and limits

- [Domain tests](../tests/Domain/AccessControl/Agent/AgentDeliveryTest.php): defaults/overrides and invalid combinations,
  claim/admission/completion, earliest expiry, pinned policy, retry/exhaustion, exact token/fence/lease/epoch/deadline,
  stale revisions, material/credential retirement and malformed persisted attempt evidence.
- [Delivery tests](../tests/Application/AccessControl/Agent/Security/AgentCredentialDeliveryServiceTest.php): real
  provision-to-delivery path and persisted receipt, no materialization after claim, all three uncertain commits in
  committed/rolled-back forms, per-stage storage rollback, caller/Permission/delegation/destination/lifecycle changes
  around every boundary, real service/direct revocation races, ABA, worker identity, deadline expiry, capacity isolation,
  original-ID retry after key outage/response loss, ciphertext swaps, unsupported sinks, receipt rejection and safe output.
- [Behavioral sink contract tests](../tests/Application/AccessControl/Agent/Security/AgentProtectedSinkContractTest.php):
  every immutable tuple field and secret bytes, missing/swapped receipts, scoped operation-ID collision, equal slot
  order rejection, late predecessor arrival, identical retry after newer writes, cleanup replay and cross-slot exact
  selection. Synthetic successor tuples specify sink behavior; they are not TASK-00048's actual rotation workflow.

These are deterministic package and in-memory boundary tests, not real database concurrency, encryption, protected
storage, consumer authority-writer or activation/use qualification. TASK-00054 integrates the remaining writers and
consumer-bindable qualification; core attempt safety is covered here rather than postponed there. This nonvisual
library change uses executable state/failure evidence, not screenshots. Full-gate counts/receipts and independent
review status belong to TASK-00051 and its ignored handoff; a green test run is not independent acceptance or release.
