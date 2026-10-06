# Agent delivery discovery and restart recovery (v0.5.0)

[TASK-00052](../planning/tasks/00052-TASK.md) adds authorized bounded discovery and receipt-first recovery to the
[protected delivery path](agent-credential-delivery.md). It does not issue credentials, rotate, activate enrollment,
launch an Agent or provide secret retrieval. Maintenance/rewrapping, reusable consumer conformance and restoration
are implemented by their owning slices; the [integration guide](agent-integration.md) connects them. This package is
**not a qualified consumer deployment**.

## Public scheduler composition

Inject `ListDueAgentDeliveriesHandler`, `AgentCredentialDeliveryService`, a trusted `Clock` and optionally
`AgentDeliverySchedule` into `AgentDeliveryRecoveryService`. As an authenticated worker with explicit current
delegation, call `recover(originalScope, registeredDestination)` once per scheduled pass. It selects one batch,
then calls the actual delivery service once per item. The safe result maps original global delivery IDs to
`AgentDeliveryResult` values. No process-local queue, event, initiating caller retry, claim token or secret is input.
Constructing new services after restart does not change recovery authority.

Call `nextRunAt()` after the pass (also after a failed pass) to obtain the next bounded polling time. Consumers own
scheduling, enumeration of their registered scope/destination pairs, worker authentication, concurrency limits and
outage backoff. Do not rediscover in an unbounded drain loop, busy-poll failures, derive scopes from events, or
impersonate the originating caller. The service neither sleeps nor schedules another job itself. The poll interval
is scheduling guidance enforced by consumer composition, not a cross-worker global rate limiter. Each pass is
bounded even if another pass runs concurrently; persisted claim/admission/attempt policy still governs each delivery.

Alternatively dispatch the public `ListDueAgentDeliveries(scope, destination, limit = 50)` Query through its handler
and schedule the returned exact keys/destinations/delivery IDs through `AgentCredentialDeliveryService::deliver()`.
The message has canonical `namespace`, `caller_type`, `caller_id`, `destination_id`, `destination_revision`, and
`limit` fields. All are required by `fromArray()`; the constructor supplies a default limit. Worker identity is not
in the DTO. Handler registration uses the Fight Common Query/QueryMessage contracts. Both compositions execute the
same package transitions; consumers must not copy claim, admission, retry or terminalization logic into workers.

## Discovery, authority and persistence

`AgentOperationRepository::listDueDeliveries(scope, destination, now, limit)` returns `list<AgentOperationView>`.
It is an authoritative, bounded, **secret-free projection**, not an entity/material load or write transaction.
The existing [safe operation representation](agent-operation-status.md) keeps original issuance and recorded
credential/delivery disposition separate. No receipt, claim token, key version, ciphertext or capability is returned.

The original scope and exact registered destination binding must match. Select unfinished pending/retryable work
with current original-credential disposition and retained material; compute its due time using the Domain rule:

```text
due = min(original issuance + pinned retention,
          max(original issuance, previous lease expiry if any, recorded retry time if any))
```

For never-claimed work the default retention applies and it is due at issuance. First claim pins delivery policy;
subsequent queries and retries use it, not a worker's new configuration.

**Exclude obsolete destination write reservations before ordering or applying the limit.** Match the issuance's
reserved write version against the authoritative durable reservation counter for the stable destination ID,
maintained by `reserveDestinationWrite()` across all Agents, scopes and binding revisions. Do not compute the
current reservation from only the matching/due operations or the sink's arrival high-water mark. A newer reservation
still excludes its predecessors when it is leased, delayed, delivered, terminal or belongs to another scope/binding.
A rolled-back reservation does not advance the committed counter. This selection rule prevents an unchanged rejected
prefix from monopolizing every restarted scheduler pass; it needs no cursor, refill loop or manual cleanup.

Order eligible work by due time ascending, then global delivery UUID bytewise ascending, applying the batch limit in
authoritative storage. Equal times are deterministic. Under the current single-slot reservation contract at most one
operation is eligible for an exact destination query; the configured batch is an upper bound, not a minimum fill.
Do not substitute event order, caller order, offset pagination or a stale replica. Consumer indexes/selection must
bound database work (resolve the current reservation before selecting work); the package's in-memory scan is a test
double, not a recommended production adapter. Consumers implement this package-owned selection contract in their
repository, not a separate scheduler filter applied after fetching a limited batch.

An active lease is not abandoned just because a process disappeared or admission expired. Discovery waits for its
lease (and any retry delay); explicit exact delivery can reconcile a still-current admitted receipt sooner.
Retention-expired eligible work stays due for authorized terminalization, not renewed admission. Delivered, retired,
terminal and expired records do not become due again. Excluding an obsolete reservation neither changes its recorded
status nor removes its material; it is not proof of credential retirement. TASK-00053 owns maintenance of obsolete
copies and expired material when no authorized delivery worker runs. Recovery of the current reservation does not
wait for that cleanup. Target/slot changes after selection may still deny work at delivery; the next pass selects
against the then-current reservation. Do not interpret rejection as absence, success or permission to create another
operation. Fresh claim/admission/completion checks remain mandatory; never bypass authorization or retry in a tight loop.

`AgentDeliveryAuthorization::authorizeDiscovery(scope, destination, issuanceOrNull, now)` is a new mandatory
read-only capability. It authenticates the **real worker**, current explicit delegation to the original scope,
Permission and destination ownership/binding before selection. The handler rechecks even an empty result, then
checks every returned original target before revealing any batch. A denied item rejects the entire batch, without
partial disclosure. Current-authority races after this read are handled independently by delivery admission; a
read allow never authorizes materialization. Consumers implement visibility policy, not package lifecycle policy.

Storage faults or malformed/unsupported snapshots produce sanitized `AgentOperationRejectedException(UNAVAILABLE)`;
current authorization denials produce `UNAUTHORIZED`. Neither becomes an empty queue. Unknown canonical versions
fail closed without reinterpreting or reissuing. Query execution does not commit, emit events or consult issuance
capacity. `getDeliveryDueAt()` owns operation-local eligibility in Domain; the Domain repository contract additionally
requires current cross-operation slot reservation. Adapters implement both as equivalent bounded selection.

## Receipt-first exact recovery

A protected sink may additionally implement `AgentCredentialReceiptLookup::lookupReceipt(issuance)`. Its optional
lookup uses the exact original tuple outside every database transaction and returns an opaque receipt or authoritative
absence, **never credential bytes**. An outage throws a typed sanitized failure; it must not masquerade as absence.
Every found receipt must independently pass `AgentCredentialSink::verify()` against the original full tuple.
Missing, swapped, unverifiable or lost entries cannot acknowledge delivery.

`deliver()` now follows these recovery rules:

1. Authorize and load authoritative operation/Agent state inside the claim transaction as before. Confirmed terminal
   history does not create another claim. An already delivered record verifies its stored receipt outside the
   transaction; missing receipt/material returns `RECONCILIATION_REQUIRED`, retaining historical delivered status.
   An unavailable verifier returns `UNAVAILABLE`. Neither path decrypts, recreates sink material or rewrites history.
2. An existing pending admission still within its deadline and bound to the same current worker/authority epoch can
   be read and confirmed for **receipt reconciliation only**. Receipt absence or no lookup capability returns
   `DEFERRED`; it never permits another decryption or stage call. Revoke/regrant ABA or changed worker epochs reject.
   A transaction whose callback ran but commit did not confirm still returns `INDETERMINATE` without sink lookup.
3. Missing/expired admission waits for claim eligibility, then gets a new fenced claim and separately committed
   admission. An uncertain admission never invokes. On a fresh confirmed admission, check sink capability and the
   deadline, then look up and verify an existing receipt before materializing. Only authoritative receipt absence
   (or an unsupported optional lookup) proceeds to the original delivery-copy decryption and fixed-slot staging.
4. Recheck the deadline after lookup and before decryption, and again before staging. Receipt verification also
   needs a fresh fenced outcome transaction. Exact operation revision, claim token/fence, worker/authority epochs,
   deadline, current credential and destination must still match. Expired or replaced old claimants cannot acknowledge,
   even when a newer claimant already recovered the same receipt. Late admitted effects remain inert.

A lookup failure before decryption records bounded temporary retry where the admission is still valid. An invalid
receipt records terminal failure, not delivery. Lost stage responses and rolled-back outcomes can therefore recover
without key access when the protected sink retains an exact valid receipt. Without optional lookup, retries remain
at-least-once using a **fresh confirmed admission**, immutable delivery ID alone, original bytes and slot write order.
Changing claim identities never changes the sink idempotency key. No exactly-once disclosure guarantee is made.

Completion removes only the delivery copy; the authentication envelope is never a fallback. If delivered sink
material is later lost, explicit reconciliation may require a separately authorized new rotation of a known active
Agent, but this service never performs it. An old delivered fact or receipt is not fresh activation/use authority.
Current consumer activation and each use still need the separate checks in the protected-sink contract.

## Finite defaults and overrides

| Setting | Default | Valid override / effect |
| --- | --- | --- |
| Discovery / scheduler batch | 50 | 1–100; query constructor and schedule reject invalid bounds |
| Poll interval | 30 seconds | 1–3600; `nextRunAt()` is for consumer scheduling, never a sleep or a persisted admission |
| Work per scheduler pass | At most 50 exact delivery calls | At most configured batch, hard maximum 100; one selection, no refill loop |
| Claim lease / admission | 60 / 15 seconds | Existing validated `AgentDeliveryPolicy`; admission cannot exceed lease |
| Retry delay | 30 seconds | Positive and no greater than retention; retry waits for both lease and retry deadline |
| Retention / attempts | 86400 seconds / 100 attempts | Existing policy limits: at most 604800 seconds / 1000 attempts |

Admission always uses the earliest claim, authorization, policy-admission and original-retention deadline. Polling
sooner cannot bypass these persisted bounds; polling later does not extend them. A changed schedule cannot extend a
pinned delivery policy. New-operation admission capacity does not apply to existing discovery, status or recovery.
Routine default-only recovery needs neither manual limits configuration nor another human approval. Storage outages
remain unavailable; no scheduling policy promises recovery while authoritative storage or current authorization is absent.

## API and verification boundaries

This is the v0.5.0 integration contract. Repository implementations must add `listDueDeliveries`, including its
pre-limit authoritative reservation exclusion (not the earlier scope/binding/due-only selection); delivery
authorization must add `authorizeDiscovery`. Sink receipt lookup is optional and explicitly capability-detected.
The existing delivery result enum adds `reconciliation_required`; consumers must handle it without auto-issuance.
The opt-in OpenAPI catalog includes the new Query, bounded unpaginated due list, shared safe operation/issuance values
and optional typed JSend envelope. These are value descriptions, not a package endpoint or worker authorization.

- [Discovery tests](../tests/Application/AccessControl/Agent/QueryHandler/ListDueAgentDeliveriesHandlerTest.php)
  prove safe deterministic bounded reads, equal-time current-write selection, cross-scope/binding exclusion,
  completed-reservation and rollback behavior, real-worker checks, target denial, revocation during selection and faults.
- [Recovery tests](../tests/Application/AccessControl/Agent/Security/AgentDeliveryRecoveryServiceTest.php) exercise
  real provisioning and actual delivery with fresh Application services, both persisted/rolled-back forms of all
  three uncertain commits, interruption around durable boundaries, receipt-first key-independent recovery,
  takeover, authority/expiry races, original identity/material, capacity isolation, bounds and terminal outcomes.
  A 51-operation regression proves default-only restarted passes deliver the current write past 50 obsolete writes,
  leave obsolete delivery denied and unchanged, and never repeat issuance. Additional tests cover reservation changes
  after discovery and current retry delays without falling back to an older due write.
- [Domain discovery tests](../tests/Domain/AccessControl/Agent/AgentDeliveryDiscoveryTest.php) and
  [delivery tests](../tests/Domain/AccessControl/Agent/AgentDeliveryTest.php) cover input/override validation,
  canonical Query round trips, due-time and recovered-admission policy.
- [Generated schema integration](../tests/OpenApi/AgentDeliveryComponentsTest.php) runs in the default PHPUnit suite
  and full gate with `CoversNothing`, keeping incidental schema-fixture execution out of exact `src/` coverage.

These are simulated restarts/interleavings over retained in-memory persistence and sink doubles, not operating-system
crashes, real database concurrency, production cryptography or consumer activation/use qualification. TASK-00049 owns
the combined provision/rotation scenario with both issuance publishers failing and caller termination; this slice
supplies its real scheduler path. TASK-00054 owns reusable consumer conformance. Neither receives deferred basic
recovery tests. Full build logs, input snapshots, counts and review status belong to TASK-00052 and its ignored handoff.
