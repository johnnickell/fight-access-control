---
id: TICKET-00012
epic: EPIC-00009
title: Issue and resolve Agent credentials recoverably
status: in-progress
---

# Issue and resolve Agent credentials recoverably

## Problem and Outcome

A committed provision or rotation can lose its response, including when post-commit publication fails. Without
operation correlation, retrying provision can create another Agent and retrying rotation can only report a stale
predecessor. An authorized caller must instead resolve the original issuance, with no repeated mutation or secret
exposure, through the breaking replacement targeted for `v0.5.0`.

Implements [EPIC-00009](../epics/00009-EPIC.md) D1–D4 and the planned amendments to
[ADR 0004](../adr/0004-agent-hmac-credential-lifecycle.md) and
[WF-002](../wayfinder/tickets/WF-002-agent-credential-revocation-lifecycle.md). John approved the three-TICKET split
on 2026-09-27. This record owns issuance, operation resolution and their behavior/conformance requirements; it is
not one PR or an implementation authorization. Public PHP names/signatures remain design work.

## Use Cases

Names describe intents, not approved class names. The synchronous provision/rotation service boundary replaces the
raw-return APIs; sensitive material never becomes a serializable Command or response.

| Actor and trigger | Command/service intent | Queries | Events and expected effects |
| --- | --- | --- | --- |
| Authorized maintainer requests a new Agent | Provision with retained scoped operation key, normalized name and registered destination binding | Operation status | Atomically commit one Agent, credential, request binding, prepared delivery, destination write reservation and audit; publish the provision fact only after commit. |
| Authorized maintainer replaces an active credential | Rotate with a new scoped key, target and expected credential ID/revision | Operation status | Commit one successor and rotation audit; atomically fence/cancel predecessor delivery under TICKET-00013's lifecycle contract; publish the rotation fact after commit. |
| Caller loses a response or restarts | Retry the original key and original request | Resolve original operation | Return original committed identity/outcome without new mutation, generation, audit or success fact. A changed request conflicts without disclosure or side effects. |
| Caller cannot determine whether commit succeeded | Same-key retry after authoritative storage recovers | Safe indeterminate result followed by resolution | Never infer rollback from missing events or automatically choose another key. Resolve committed versus rolled-back state before reporting success. |
| Originating caller or explicitly delegated worker requests status | N/A: a read is not an issuance command | Authorized secret-free operation status | No mutation, commit or event from the query. Return original issuance metadata separately from current lifecycle/delivery disposition. |
| New work reaches operation capacity | Provision or genuinely new rotation | Existing-operation status/resolution remains supported | Reject or defer new work with a clear safe retryable outcome and no partial issuance; preserve authorized recovery of existing operations. |

## Request Identity and Validation

- The caller generates an opaque operation ID using the owning factory/UUID library and retains it before calling.
  The trusted application supplies consumer/authority namespace plus authenticated caller type and stable ID; an
  untrusted payload cannot choose its own authority scope. Operation IDs, Agent IDs and non-unique names are not
  authentication or recovery capabilities.
- Bind the scoped key to a package-canonical, versioned, secret-free request: operation kind; normalized provision
  name; or rotation target and expected credential ID/revision; immutable registered destination identity/binding
  revision; and every outcome-affecting option. The package computes equality/digest, not a caller-supplied hash.
- Same key/request resolves its original outcome. Changes to kind, name, target, predecessor, destination or options
  conflict before generation/mutation/disclosure. Equivalent canonical requests remain equivalent. Equal opaque IDs
  in different scopes are different operations. Resolve scope authorization before exposing conflicts or existence.
- Preserve the original rotation request on retry. An authorized existing-key resolution does not rerun the now-stale
  predecessor mutation check; a genuinely new rotation requires a new key, fresh authorization and current predecessor.
- Persist canonical-request version and sufficient safe request data. Look up an authorized key before selecting the
  canonicalizer, including tombstones. Existing keys use their recorded version, unseen keys use the agreed creation
  version, and unknown versions reject without falling through to issuance. Never rewrite old bindings under new
  normalization. [TICKET-00014](00014-TICKET.md) owns upgrade/cohort compatibility proof.

## Atomic Issuance and Authorization

- One package-owned `TransactionalUnitOfWork` commits operation/request, Agent mutation, credential identity/revision,
  integrity-bound encrypted delivery material/reference, pending delivery state, destination write reservation and
  safe audit together. Repositories and authorization participation use the same connection; no outer transaction
  wraps legacy transactional services, and no audit-adapter side effect supplies correlation.
- Consumer policy owns caller, target, delegation and destination decisions. Its injected capability reads current
  authority and holds revisions/epochs and authorization/version fences until package commit without beginning or
  committing a transaction. All consumer authority mutations and package lifecycle paths must honor the same fences,
  including CLI, worker and direct service calls. Supplied booleans, actor strings and external cached allow decisions
  are not admission authority. An external provider needs an authoritative fenced local grant or is unsupported.
- Authenticate and authorize every invocation, retry/no-op and status read. Delegated workers authenticate as
  themselves and receive explicit current authority for the original scope; they neither impersonate the originator
  nor rewrite the recorded scope. No package Permission names or generic actor-only administration ports are added.
- Create one globally unique immutable delivery ID per committed issuance. Reserve a strictly increasing write
  version under the destination slot's shared ownership/reassignment fence; ordering spans Agents and caller scopes.
  Record delivery ID, original Agent/credential tuple, destination binding and write order atomically. No caller URL
  or path is accepted as a destination; rebind/reuse cannot reset ordering. TICKET-00013 owns sink enforcement.
- Concurrent identical requests have one committed winner. Unique-key/CAS losers roll back then resolve that winner
  or report conflict; no second Agent, authoritative generation or audit survives. Different rotation keys against
  one predecessor have at most one successor. Pre-commit audit, encryption, authorization or persistence failure
  rolls back all state. Storage uncertainty is not proof of rollback.
- Keep one active credential, immediate predecessor retirement, terminal revocation and the authentication nonce/
  current-authority fence. Delivery ciphertext is separately integrity-bound from the authentication envelope; both
  may be created during issuance but their lifetimes differ. Unavailable pre-commit key/encryption access rolls back.

## Results, Publication and Recovery

- Only a confirmed issuance commit returns committed Agent/credential/revision and safe operation metadata. A
  post-commit publisher failure adds a **typed, sanitized publication warning**, not a publication-failure throw.
  Preserve distinct pre-commit failure and indeterminate-commit outcomes. Arbitrary exceptions, raw secret,
  ciphertext, key paths, provider errors and general decryption handles never enter safe results or events.
- The warning/rethrow exception applies only to replacement provision and rotation, not revocation,
  AuthenticationService or unrelated handlers. Retain safe failure evidence without letting its own publication
  failure destroy or disguise an outcome. No success publication may precede confirmed commit.
- Durable issuance/delivery records determine recovery even when both publishers fail and the caller never retries.
  TICKET-00013's authorized scheduler must discover their work after restart. Retrying an operation does not emit
  another mutation/audit fact; any notification retry retains stable fact identity and documented duplicate semantics.
- Status separates original issuance from current delivery/lifecycle: pending, delivered, superseded, revoked,
  expired, retryable storage/key failure, terminal delivery failure and indeterminate outcome. A retired operation
  identifies its original credential, never a replacement secret. Reads expose no secret or secret-read handle.
- Committed issuance, with or without a warning, does not establish delivery, enrollment activation or permission to
  launch. Those require separate confirmed outcomes and current authorization, not inference from an event or status.

## Bounded Policy and Design Completion

Provide documented finite defaults and optional validated overrides for input sizes and outstanding-operation
admission. Routine use requires no manual configuration or extra human approval; current authorization still applies.
Invalid overrides reject safely. Capacity rejection/deferral must be clearly retryable and must not block authorized
status, same-key resolution or recovery of existing operations, evict required correlation, or silently change keys.

Before implementation acceptance, requirement/design work must document concrete defaults, ranges, validation,
canonical request versions, safe outcome shapes and the capacity/recovery interaction with TICKET-00013. This is a
bounded credential-operation contract, not a general quota system. Retain safe operation outcomes/tombstones so
cleanup or expiry never makes an old key fresh issuance; pending-copy/key retirement and sink cleanup belong to
TICKET-00013. An unavailable database may prevent reads temporarily; it never permits fabricated outcomes.

## Acceptance Evidence

All items are future proof, not evidence executed during planning. Assert persisted outcomes and interleavings.

- [ ] **I1 — Normal issuance and safe status:** provision and rotation each commit exactly one intended mutation,
      audit, credential and discoverable delivery, with no raw response secret. Status preserves original identity
      independently of delivery/retirement. Confirmed issuance cannot imply delivery, activation or launch authority.
- [ ] **I2 — Same-key recovery and concurrency:** lost response plus caller/service restart resolves the original
      provision and original rotation request, including a no-longer-current predecessor. Canonical equivalents
      resolve; each changed request field conflicts. Concurrent identical/conflicting keys and competing rotations
      leave one authoritative winner, no repeated generation on committed-key retry and no duplicate audit.
- [ ] **I3 — Commit/failure distinctions:** pre-commit audit/encryption/authorization/persistence failures leave no
      partial state. Inject connection loss with both committed and rolled-back results for each issuance operation;
      return indeterminate then resolve the same key after restart, never auto-selecting another key.
- [ ] **I4 — Publication independence:** for both operations, confirmed commit plus publication failure returns typed
      sanitized warning metadata. Failure-publication failure cannot replace the outcome. Together with TICKET-00013,
      terminate the caller after both publishers fail; a restarted authorized scheduler completes pending/expired-claim
      work without caller retry, duplicate mutation/audit or reliance on an event. Notification retries retain fact ID.
- [ ] **I5 — Authority and reservation:** wrong namespace/caller, revoked authority, expired delegation and unauthorized
      destination deny retries/status as well as new issuance without existence leaks. Workers retain original scope.
      Cross-scope equal operation IDs produce distinct delivery IDs; shared-slot reassignment advances order. Public
      conformance scenarios prove issuance versus authority/lifecycle writers and transactional capability rollback.
- [ ] **I6 — Bounded defaults and retained keys:** document/test default-only operation, valid/invalid overrides and
      capacity rejection/deferral preserving existing status/resolution/recovery. Tombstoned keys cannot issue again;
      unknown canonical versions reject. Upgrade cases also run under TICKET-00014.
- [ ] **I7 — Public replacement and safety:** old raw-return signatures are removed or explicitly rejected, not given
      implicit keys/destinations. Safe serialization, debug, events, warnings, audit and failure diagnostics contain
      no secret/ciphertext/unsafe provider detail. Existing authentication authority invariants remain proven.
- [ ] Owned Domain/Application tests and consumer-bindable persistence/authorization conformance are supplied;
      `./bin/planning-check` and the complete `./bin/build` pass for implementation. Real adapter execution is required
      before claiming consumer concurrency/transaction support; in-memory success is not PostgreSQL proof.

## Dependencies, Sequencing and Exclusions

Design operation/delivery identity, authority/destination fences and atomic lifecycle cancellation jointly with
[TICKET-00013](00013-TICKET.md). This TICKET owns creating/resolving the committed work; that TICKET owns claim,
admission, sink effect and all-path cancellation behavior. The split is not permission to ship issuance without
those fences or an unfenced temporary rotation path. [TICKET-00014](00014-TICKET.md) depends on both contracts for
migration/compatibility and owns the complete scenario-coverage map, not deferred ownership of these behavior tests.

No sink invocation/worker lifecycle implementation, production adapters, schemas, SQL lock choice, external identity
provider protocol, transport endpoints, consumer Permission catalog, enrollment/broker implementation, generic outbox,
release/tag/publication, consumer upgrade or Agent OS TASK-00138 closure is included. Query reads have no side-effect
events; there is no general secret query. Completed HMAC, email-delivery and Permission-tier records remain history.

## TASK Ownership and Readiness

John requested TASK planning for this TICKET and approved the four-slice proposal on 2026-09-27. The resulting
feature/conformance TASKs normally own one PR each; they are not layer-only assignments. Parented work does not use
the standalone bug/chore `kind` exception.

| Approved slice | TASK | Dependency/readiness boundary | Acceptance ownership |
| --- | --- | --- | --- |
| A — Provision through a recoverable operation | [TASK-00046](../tasks/00046-TASK.md) | Ready, no unfinished TASK blocker | I1–I7 for the complete provisioning/service-retry path, shared contracts, finite defaults and focused failure/concurrency tests. |
| B — Read operation outcomes safely | [TASK-00047](../tasks/00047-TASK.md) | Done for accepted implementation/local verification; TASK-00046 dependency completed | I1/I5/I6/I7 read/status and concealment proof; no mutation/commit/event and no inferred delivery/activation/launch authority. |
| C — Rotate through a recoverable operation | [TASK-00048](../tasks/00048-TASK.md) | Done for accepted implementation/local verification; TASK-00046/00050/00051 done | I1–I7 for rotation and atomic predecessor cancellation; original-request recovery and core failure/race tests stay here. |
| D — Publish and exercise issuance-recovery conformance | [TASK-00049](../tasks/00049-TASK.md) | Ready status; waits for TASK-00046/00047/00048/00052/00053 | Reusable conformance across I1–I7 and complete I4 scheduler-only recovery without either publisher or caller retry; not a substitute for A–C tests. |

John subsequently approved TICKET-00013's five-TASK split and these dependency updates on 2026-09-27.
[TASK-00050](../tasks/00050-TASK.md) supplies cancellation/fencing and [TASK-00051](../tasks/00051-TASK.md) supplies
actual admission/in-flight delivery for TASK-00048's accepted race tests. [TASK-00052](../tasks/00052-TASK.md) supplies
scheduler/reconciliation and [TASK-00053](../tasks/00053-TASK.md) supplies cleanup/retention for TASK-00049's integrated
proof. Their missing-information holds are resolved by real records, not by completed implementation. The graph is
acyclic: downstream work starts from TASK-00046; neither this TICKET as a whole nor either conformance TASK blocks
its own implementation inputs. All unfinished blockers must be terminal before execution.

## Progress

TASK-00046 is independently accepted and done for the provision/service-retry implementation and focused
failure/recovery tests, not merged or released by that status. [Its public contract](../../docs/agent-provisioning-operations.md)
records concrete interfaces, bounds and I1–I7 provisioning evidence. Its complete package gate passes: 659 tests,
4973 assertions and 5281/5281 statements covered; TASK-00046 records the review, receipts and limitations.
TASK-00046 is now merged through PR #79. John authorized TASK-00047 in the main checkout on
`feature/task-00047-operation-status`; its query/status implementation is independently accepted and done for
implementation/local verification, not merge or release. John's landing request published
[PR #80](https://github.com/johnnickell/fight-access-control/pull/80) against develop. The
[status contract and I1/I5/I6/I7 evidence map](../../docs/agent-operation-status.md) record safe projection,
current-authority/concealment, retained-version, capacity/recovery and secret-safety tests. Read-side proof includes
real provision-to-query recovery and persisted fixtures for downstream dispositions, not rotation/delivery writers
or consumer qualification. TASK-00048's prerequisites are now done; John authorized its execution in the main
checkout on `feature/task-00048-rotation`. Its [rotation contract](../../docs/agent-rotation-operations.md) and tests
cover original-request resolution, atomic supersession, uncertain commits, publication independence and actual
in-flight delivery/authentication races. Independent review accepted `e667116` with all TASK criteria passing and
no findings; TASK-00048 is done for implementation/local verification. John's landing request published
[PR #83](https://github.com/johnnickell/fight-access-control/pull/83) against `develop`; it is open at this checkpoint,
not merged or released. TASK-00049 still waits on its remaining prerequisites.
All TICKET acceptance boxes remain open: scheduler and reusable conformance are outstanding.
Consumer qualification and release remain separately required.

The local live/archive inventory was rechecked before allocation at unchanged HEAD
`92d1de82a7833cc6dafb90eccea0d132f0e3cd77`: existing TASKs ended at 00045 and no matching archived recovery TASK was
found. The existing uncommitted EPIC/TICKET/guidance work and unrelated TASK-00035 are preserved. Sequential
orders 46–49 rank these newly approved slices after the existing portfolio without reprioritizing other records.

John separately authorized TASK-00046 execution on `feature/task-00046-provision` in the isolated
`.runs/worktree/task-00046-provision/` checkout. Other TASKs retain their own authorization requirements. At the planning-only
checkpoint before publication authorization, no implementation, release, Agent OS dependency upgrade, TASK-00138
closure, commit or publication had occurred. Intermediate PRs are reviewable
unreleased work, never a supported partial lifecycle/delivery protocol. Planning verification is recorded in the
parent EPIC; no runtime test/build or consumer qualification result is claimed.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 46 | [TASK-00046](../tasks/00046-TASK.md) | Provision an Agent through a recoverable operation | done |
| 47 | [TASK-00047](../tasks/00047-TASK.md) | Read Agent operation outcomes safely | done |
| 48 | [TASK-00048](../tasks/00048-TASK.md) | Rotate an Agent through a recoverable operation | done |
| 49 | [TASK-00049](../tasks/00049-TASK.md) | Publish and exercise issuance-recovery conformance | ready-for-agent |
