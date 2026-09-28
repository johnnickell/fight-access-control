---
id: TICKET-00013
epic: EPIC-00009
title: Deliver and recover Agent credentials through protected sinks
status: in-progress
---

# Deliver and recover Agent credentials through protected sinks

## Problem and Outcome

Committed issuance is not credential delivery. A worker can lose authorization, crash, lose a sink response or be
replaced by another claimant while an old call is still in flight. An authorized worker must deliver the original
credential only to its bound protected destination, recover safely without another issuance, and never acknowledge
or activate retired authority. Delivery is at-least-once, not exactly-once secret disclosure.

Implements [EPIC-00009](../epics/00009-EPIC.md) D1–D4 for target `v0.5.0`, the
[ADR 0004 amendment](../adr/0004-agent-hmac-credential-lifecycle.md) and
[WF-002 amendment](../wayfinder/tickets/WF-002-agent-credential-revocation-lifecycle.md). John approved this requirement
area on 2026-09-27. The package owns policy and public contracts, not a production secret-store adapter.

## Use Cases

Intent names are not final PHP signatures. Sensitive invocation is non-serializable and distinct from safe
Commands/Queries/Events. Events are optional coordination, never delivery or recovery authority.

| Actor and trigger | Command/service intent | Queries | Events and expected effects |
| --- | --- | --- | --- |
| Authorized scheduler starts or restarts | Dispatch exact delivery work | Bounded due-work discovery: pending, due retry and expired claims | Reads publish no events and do not mutate. Work survives missing issuance events and caller abandonment. |
| Authenticated delegated worker receives work | Claim then admit the exact delivery | Safe delivery/operation status | Persist claim fence/token/lease, then separately commit current sensitive admission; no secret access follows claim alone. |
| Admitted worker invokes the bound sink | Deliver outside every database transaction, reconcile receipt and acknowledge | Safe status; protected receipt reconciliation where supported | Commit completion only for a current claim/admission and verified exact receipt. Any delivery fact follows its committed outcome. |
| Worker loses response or outcome commit | Retry/reconcile the same delivery ID and original bytes | Durable status/receipt lookup | New current authority may reconcile existing receipt; no new secret generation, raw fallback or stale claimant acknowledgement. |
| Maintainer rotates or revokes through any public/direct path | Existing replacement rotation/revocation intents | Original operation's retired disposition | Atomically cancel/fence predecessor work with credential mutation. Existing lifecycle facts retain their contract; D3's publication-warning exception does not extend to revocation. |
| Worker encounters transient key/sink failure or retention expiry | Record retryable/terminal outcome and retire delivery copy when appropriate | Discoverable retry state or safe terminal status | Bounded recovery retains original identity/bytes; terminal state prevents re-delivery/revival. No unsafe vendor diagnostic enters audit/events. |
| Authorized consumer checks enrollment/use readiness | Consumer-owned activation/use, not a new package activation command | Exact confirmed delivery plus current authority | Issuance, receipt or old admission alone grants no activation/launch/signing authority. Consumer composition proves its own fresh authorization. |

## Identity, Claim and Admission Contract

- Use [TICKET-00012](00012-TICKET.md)'s atomically prepared operation, original Agent/credential tuple, globally unique
  delivery UUID, destination binding and reserved slot write version. Delivery ID is immutable across retries,
  restarts, claim takeover and key rewrapping; it is the sink idempotency key **alone**. A changing claim token/fence
  is not that key. Agent revision orders one Agent; slot write version orders all Agents/scopes sharing the slot.
- Discovery and status are deterministic, bounded and secret-free, with current scope/delegation checks before
  exposing work or existence. A worker authenticates as itself and is currently delegated to the originating scope;
  possession of an operation/delivery ID or an actor string is not authorization.
- Claim commits one monotonic attempt fence, opaque token and lease. It only reserves work. Immediately before
  materialization, a short package-owned admission transaction checks authenticated caller/delegation and original
  scope, current destination owner/binding/write version, exact active credential, pending state, claim validity and
  retention. Consumer authorization participates on the same connection and holds authoritative revisions/epochs
  and fences through commit, without its own begin/commit.
- **Successful admission commit linearizes permission to materialize/invoke.** Read authoritative state and trusted
  time at that final decision. Record authorizing revisions/epochs and the earliest claim, authorization and delivery
  deadline. Every authority mutation, revocation, destination reassignment and package lifecycle writer shares the
  fences. An external cached allow decision or nested outer transaction is unsupported.
- On uncertain admission commit, do not decrypt or invoke. Resolve durable admission and obtain a fresh valid
  claim/admission if absent or expired. Recheck the recorded deadline after commit and before materialization and
  invocation; a commit returning after expiry cannot start either. This guard is not cross-system rollback.
- Revocation/reassignment/supersession before admission denies without materialization. If it commits after admission,
  an already-admitted call may finish only at the fixed sink, potentially staging inert bytes. Expiry during a call
  has the same limitation. No database operation can recall disclosed bytes or atomically cancel an external effect.
- Acknowledgement freshly checks exact claim and admission epochs/deadline plus current credential/destination.
  Takeover, expiry, revocation or any authority change invalidates the old claimant, including revoke/regrant ABA.
  A new authorized claim/admission may reconcile an existing exact receipt, but cannot revive retired authority.

## Protected-Sink and Receipt Contract

- Sink invocation happens outside every issuance, claim, admission and outcome transaction. Raw material exists only
  in a short-lived non-serializable sensitive invocation bound to one server-registered slot identity/revision; no
  caller-selected URL/path or general decryption handle is exposed.
- Bind the immutable delivery ID to original secret bytes and the exact Agent ID, credential ID/revision,
  destination identity/binding revision and reserved write version. Same key with different tuple or bytes rejects
  without writing. An opaque, non-secret durable receipt attests that binding; missing, swapped or unverifiable
  receipts cannot complete delivery. Public receipts/status contain no secret digest or read capability.
- Sink writes atomically stage immutable versioned entries and advance a per-slot high-water mark without lowering
  it. Equal version with different binding rejects; an identical retry may return its original receipt without
  changing selection. Reassignment/reuse never resets order. Retain deduplication/high-water evidence through cleanup
  so delayed calls cannot recreate retired material. Unsupported sinks fail closed.
- A delayed predecessor cannot overwrite, select or reactivate newer material. Before the sink knows a newly
  committed package rotation, late predecessor bytes can only be staged inert. Consumer selection must use the exact
  current package/destination tuple, not latest arrival or a sink pointer. Cover rotation to another slot and slot
  reassignment across Agents/caller scopes, not only retries of one operation.
- Delivery confirmation is distinct from consumer enrollment activation and use. Activation needs confirmed delivery
  of the exact tuple and a fresh consumer authorization transaction. Each broker use checks current consumer use and
  package credential/destination authority. A receipt or once-valid admission grants neither continuing access nor
  permission to launch; late effects and receipts cannot activate retired authority. Consumer implementation is separate.

## Recovery, Lifecycle and Secret Lifetime

- Lost sink response or outcome commit retries/reconciles the same delivery ID and original bytes. Where supported,
  reconcile durable receipts before unnecessary materialization. Repeated admitted calls are possible; document
  at-least-once effects. If outcome commit actually succeeded, status resolution must not redeliver; if it rolled
  back, reconcile under a current claim/admission. Unknown outcome must not be reported as delivered.
- Every retained rotation/revocation entry point, including direct non-HTTP calls, atomically fences/cancels pending
  predecessor delivery with Agent mutation. Pending cancellation cannot depend on events, key access or sink access.
  Preserve immediate single-credential replacement, terminal revocation and authentication fencing. No old signature
  may keep an unfenced mutation implementation. TICKET-00012 owns rejecting legacy raw-return issuance signatures.
- Delivery ciphertext is separate in lifetime and integrity binding from the Agent authentication envelope. Bind
  it to the delivery/credential/destination tuple so swapped/corrupt material fails closed. Retire it on acknowledged
  completion or terminal expiry/revocation/supersession; retain safe correlation. Authentication-envelope lifecycle
  is not changed by delivery-copy cleanup and never supplies a delivery fallback.
- Temporary sink/key/decryption failure after issuance retains bounded discoverable ciphertext, key version and
  sanitized retry state. Recovery uses original bytes/ID. Permanent key loss, corruption or exhausted retention
  gives safe terminal failure for explicit reconciliation. Cipher/sink adapters return typed sanitized classifications,
  not arbitrary provider errors. Rewrapping preserves plaintext/binding/identity; key retirement accounts for pending copies.
- After acknowledged delivery, recovery is receipt/status lookup, not another secret-read facility. If the sink later
  loses its material, fail closed for reconciliation. Known active Agents may need an explicitly authorized new
  rotation/new key; ambiguous old provision needs human reconciliation. Revoked Agents cannot revive. Routine retries
  do not need an extra human approval step. Never auto-rotate/provision, restore a predecessor or mark enrollment ready.

## Bounded Policy and Design Completion

Document finite defaults and optional validated overrides for discovery batch/work bounds, claim/admission duration,
retry scheduling, delivery retention and inert-entry cleanup. Validate relationships with authorization expiry,
trusted time and TICKET-00012's admission limits. No manual configuration is required for routine operation/recovery;
invalid overrides reject safely, not disable safety. Capacity policies must reserve the ability to look up and
recover existing operations while rejecting/deferring new work with a clear retryable outcome. Bounded scheduling
must prevent runaway recovery without requiring routine human approval or promising availability during storage outages.

Concrete defaults, override ranges, expected-state transitions, receipt/cipher representations and cleanup mechanisms
must be documented in requirement/design work and proven before implementation acceptance. Cleanup may remove secrets
but preserves enough operation/deduplication/order evidence to prevent duplicate issuance and stale delivery. It never
makes an old key reusable. No general quota, key-vault or approval framework is introduced.

## Acceptance Evidence

These are future requirements. Each test asserts durable outcomes, secret safety and relevant interleavings.

- [ ] **D1 — Discovery and restart:** default-only workers discover bounded pending, due-retry and expired-claim work
      with current delegation. Together with TICKET-00012, both issuance publishers fail, the caller terminates and
      never retries, and a restarted scheduler delivers without repeated issuance/audit or event dependency.
- [ ] **D2 — Admission authority:** interleave caller revocation, Permission removal, delegation expiry, destination
      reassignment and credential retirement before claim, between claim/admission, before materialization, during
      invocation and before acknowledgement/activation. Test deadline expiry and revoke/regrant ABA. Pre-admission
      denial never materializes; post-admission effects stay inert and cannot acknowledge/activate/use stale authority.
- [ ] **D3 — Uncertain commits and takeover:** test committed/rolled-back admission and outcome commits, sink
      acceptance with lost response, outcome failure, competing retries and expired/replaced claims. Unconfirmed
      admission never decrypts/invokes; current reconciliation preserves original ID/bytes and rejects stale acknowledgement.
- [ ] **D4 — Sink identity/order:** equal opaque operation IDs in different scopes have distinct delivery IDs even at
      equal claim counts. Swap every receipt/idempotency tuple field or bytes; mismatches deny. Delay predecessor
      before and after sink acceptance of a successor, rotate across slots and reassign slots across Agents/scopes.
      Old arrivals cannot overwrite/select/reactivate; matching retries return receipts without changing selection.
- [ ] **D5 — All-path retirement:** each retained rotate/revoke path races admission and in-flight delivery, atomically
      cancels predecessor work without key/sink access, and prevents stale acknowledgement/activation. Original status
      remains its own retired credential, never a successor secret. Revocation's publication contract is unchanged.
- [ ] **D6 — Secret/key/terminal recovery:** transient failure retains pending material/version; restored access and
      rewrapping deliver original bytes/ID. Corrupt/swapped ciphertext, permanent key loss, retention expiry and
      delivered-secret loss fail safely without envelope fallback, raw output, reissue or revived delivery. Delayed
      replay after cleanup cannot bypass tombstones/high-water marks. Serialization/debug/audit/events remain safe.
- [ ] **D7 — Bounded operation:** document/test finite defaults, valid/invalid overrides, bounded retries/leases,
      capacity isolation of existing recovery/status, and cleanup safety. Routine recovery requires current authority,
      not mandatory manual configuration or another human approval.
- [ ] Package Domain/Application tests and consumer-bindable authorization, persistence and sink conformance prove
      the observable contract, including external calls outside transactions and sanitized failures. Run
      `./bin/planning-check` and `./bin/build` for implementation. Real adapter/sink execution and fresh consumer
      activation/use authority proof are required before supporting that consumer; in-memory doubles alone do not qualify it.

## Dependencies, Sequencing and Exclusions

Depends on TICKET-00012's committed operation/delivery contract. Design the shared identities, issuance reservation,
current-authority fences and lifecycle cancellation jointly before slicing implementation. Issuance cannot be safely
shipped independently of all-path cancellation/admission requirements. This is a requirements dependency, not a new
TASK `blocked_by` edge. [TICKET-00014](00014-TICKET.md) owns compatibility/migration and scenario traceability; these
behavior tests and public conformance scenarios remain owned here, not postponed to that TICKET.

Excluded: production database/sink/cipher/authorization adapters, concrete SQL/lock ordering, host secret-store
selection, transport endpoints, consumer Permission names, enrollment/broker implementation, general secret reads,
exactly-once disclosure, generic outbox or job framework, release/publication, consumer upgrade and Agent OS
TASK-00138 closure. Credential expiry/grace authority and Agent revival remain unsupported. Safe discovery queries
have no mutation/event side effects; consumer activation is not a package Command/Event addition.

## TASK Ownership and Readiness

John approved the five-slice decomposition and associated TICKET-00012 dependency updates on 2026-09-27. Each TASK
normally owns one independently reviewable PR and its focused tests. These are parented features/product conformance,
so the standalone bug/chore `kind` exception does not apply.

| Slice | TASK | Blockers | Delivered outcome and acceptance ownership |
| --- | --- | --- | --- |
| A | [TASK-00050](../tasks/00050-TASK.md) | TASK-00046 | Atomic revocation/cancellation and all-path write fences; D2/D5/D6/D7 state/rollback proof, not yet real delivery integration. |
| B | [TASK-00051](../tasks/00051-TASK.md) | TASK-00050 | Complete claim/admission/protected sink/receipt/outcome path; D2/D3/D4 and relevant D5/D6/D7 fail-closed behavior. |
| C | [TASK-00052](../tasks/00052-TASK.md) | TASK-00051 | Authorized bounded discovery and interrupted-delivery reconciliation; D1/D3 and relevant D2/D6/D7 restart proof. |
| D | [TASK-00053](../tasks/00053-TASK.md) | TASK-00051 | Rewrapping, key accounting, retention and cleanup; D6/D7 plus D4/D5 cleanup/replay proof. |
| E | [TASK-00054](../tasks/00054-TASK.md) | TASK-00052, TASK-00053, TASK-00047, TASK-00048 | Consumer-bindable delivery/lifecycle conformance across D1–D7 using actual package paths; no deferred basic tests. |

TASK-00048 now depends on TASK-00050's cancellation contract and TASK-00051's actual admission/in-flight path, in
addition to TASK-00046. TASK-00049 now depends on TASK-00052's scheduler/reconciliation and TASK-00053's maintenance/
cleanup path, in addition to TASK-00046/00047/00048. Their missing-information holds become ordinary dependency waits,
not completed prerequisites. Neither conformance TASK blocks its own implementation inputs or the other conformance
TASK. This avoids a whole-TICKET cycle; shared issuance contracts begin with TASK-00046.

Core default/override, authority, failure and secret-safety tests stay in A–D. TASK-00049 retains the combined
provision/rotation proof with both issuance publishers failing and no caller retry; TASK-00052 supplies its real
scheduler path. TASK-00054 qualifies delivery/lifecycle integration and reusable consumer bindings. Real consumer
adapter/sink/activation/use proof remains separately required before supporting adoption. TICKET-00014 retains
migration/cohort/restore scope and the complete scenario map.

## Progress

TASK-00046's accepted implementation is merged. John authorized TASK-00050 in the main checkout on
`feature/task-00050-retirement`; atomic revocation/cancellation, Domain successor validation and controlled
expected-state race/rollback tests are locally verified. Independent review requested R1 failure/debug redaction;
the correction annotates replacement arguments and transaction callbacks, with six regression cases and a fresh
complete gate (713 tests / 6651 assertions, exact 5470/5470 statement coverage).
Independent re-review accepted clean head `3aad9e6` with R1 resolved and all eight TASK criteria covered; fresh
focused checks passed (155 tests / 2095 assertions), and the complete gate's 650 tracked inputs matched that head.
TASK-00050 is done for implementation/local verification; its PR #81 is now merged into `develop` at `6d52e8d`.
John authorized TASK-00051 in the main checkout on `feature/task-00051-protected-delivery`. The
[protected delivery attempt](../../docs/agent-credential-delivery.md) is implemented and locally verified (840 tests /
7276 assertions, exact 5742/5742 statements). Independent review accepted `5f3f596` with no findings, 216 focused
tests / 2232 assertions and all 674 saved gate inputs verified. TASK-00051 is done for implementation/local
verification. John's landing request published
[PR #82](https://github.com/johnnickell/fight-access-control/pull/82) against `develop`; no merge or release is claimed.
John subsequently authorized TASK-00052 in the main checkout. Its
[discovery/recovery slice](../../docs/agent-delivery-recovery.md) now implements bounded delegated discovery,
event-independent scheduler passes and receipt-first recovery through the actual delivery path. Focused tests cover
persisted restart/uncertainty, receipt lookup, current authority, takeover and finite bounds. Its complete local gate
passes with 952 tests / 8148 assertions and exact 5971/5971 statement coverage; saved evidence and acceptance mapping
belong to TASK-00052. Independent review of `1146b7c` requested R1: obsolete slot writes could monopolize every
bounded batch. The revision requires pre-limit authoritative current-reservation selection across scopes/bindings,
with a failing-then-passing 51-operation scheduler regression, unchanged delivery authorization and explicit adapter
obligations. Focused revision verification passes 269 tests / 2140 assertions plus PHPCS/PHPStan; the complete
revision gate passes 957 tests / 8350 assertions with exact 5971/5971 statements. Logs, initial failures and input
mapping belong to TASK-00052. Independent re-review accepted `c9e23ab` with R1 resolved and all nine TASK criteria
passing, fresh 269 tests / 2140 assertions, and all 696 gate inputs matched. TASK-00052 is done for implementation/local
verification, not consumer qualification. John requested landing; the latest develop's TASK-00060 planning-only update
is integrated without changing the reviewed implementation. At the initial publication checkpoint,
[PR #85](https://github.com/johnnickell/fight-access-control/pull/85) is open against `develop` at `0a08437`, with the
fresh full gate passing 957 tests / 8350 assertions and exact 5971/5971 statements. The TASK's ignored landing handoff
owns the review bridge, final metadata verification and publication evidence. Merge and consumer qualification remain
outstanding.
John authorized TASK-00053 in the main checkout on `feature/task-00053-delivery-material`. Its
[maintenance contract](../../docs/agent-delivery-maintenance.md) implements rewrap/expiry/cleanup, safe keyset reads,
global diagnostic reference accounting and consumer key-retirement/replay obligations. Its complete local gate passes
1047 tests / 8980 assertions and exact 6245/6245 statements; the owning TASK links logs/receipt and criterion mapping.
Independent review accepted `9a0a391` with all nine TASK criteria passing and no findings, 526 fresh focused tests /
5057 assertions, and all 718 saved gate/bridge inputs verified. TASK-00053 is done for implementation/local verification;
John requested landing, with publication pending at this completion checkpoint. TASK-00054's implementation inputs
are now done; its reusable integration/conformance work still requires execution authority. All TICKET acceptance
boxes remain open until their full owning-slice evidence, including that conformance, is independently accepted. See the
[retirement contract](../../docs/agent-credential-retirement.md) for the slice's public write obligations and limits.
Concrete finite values, override validation, public shapes and expected-state details must be documented/tested in
the owning slices before acceptance; this plan neither silently selects production values nor adds routine approval.

Before allocation, refreshed local live/archive inventory at unchanged HEAD
`92d1de82a7833cc6dafb90eccea0d132f0e3cd77`: live IDs ended at 00049, archive held only its README and no duplicate
Agent-delivery TASK was found beyond the known TICKET-00012 shared work. Orders 50–54 preserve existing portfolio
priority; no remote inventory was queried. Existing uncommitted work and unrelated TASK-00035 are preserved.

At the planning-only checkpoint before publication authorization, no implementation, runtime/build evidence,
real-adapter qualification, commit, publication, release, consumer upgrade or Agent OS TASK-00138 closure was claimed.
Implementation and branch/worktree choice still require separate authority.
Intermediate PRs remain unreleased work, not supported partial compositions. Planning checks are recorded in the
parent EPIC after regeneration.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 50 | [TASK-00050](../tasks/00050-TASK.md) | Revoke Agent credentials and fence pending delivery | done |
| 51 | [TASK-00051](../tasks/00051-TASK.md) | Deliver an Agent credential to its protected destination | done |
| 52 | [TASK-00052](../tasks/00052-TASK.md) | Discover and recover interrupted Agent deliveries | done |
| 53 | [TASK-00053](../tasks/00053-TASK.md) | Maintain and retire protected delivery material safely | done |
| 54 | [TASK-00054](../tasks/00054-TASK.md) | Qualify protected-delivery and lifecycle conformance | ready-for-agent |
