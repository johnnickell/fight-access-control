# Approve the shared human-delivery expiry contract

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Approved for planning; checkout/worktree placement remains required before implementation
**Map:** [Human credential-delivery expiry cleanup](../human-credential-delivery-expiry-map.md)
**Depends on:** —

## Question

Provide bounded package-owned expiry work for invitation/reset delivery and complete email-change authority/reservation
expiry, without omitting work whose delivery is already terminal or whose User is no longer active?

## Decided constraints from John's request

- Required outcome: a complete package-owned discovery/terminalization path for invitation and reset work left
  `pending`, `retry_pending` or `claimed` when the application remains offline through grant expiry.
- Discovery is bounded, deterministic, secret-free and read-only. Queries do not mutate, commit or publish facts.
- Direct expiry uses existing aggregate transitions and transactional Domain repositories. Revalidate expiry
  eligibility, ownership and authoritative generation inside the transaction, with complete-state CAS and stale-worker
  fencing. State, ciphertext removal and removal of claim token/time/lease commit together or all roll back.
- Neither decryption nor provider invocation is needed. Preserve immutable delivery identity, attempt count, last
  attempt/outcome and safe failure history. Repeated passes do not duplicate business facts or write unchanged state.
- Replacement, consumed, cancelled/revoked, terminal or newer-generation state must not be overwritten by cleanup or
  delayed worker outcomes. Preserve current package lease/retry/backoff policy; no other queue or consumer state machine.
- Assess email change consistently with its existing semantics without enabling consumer product/provider wiring.
- Follow [ADR 0011](../../adr/0011-pre-v1-current-contract-only.md): current contract only, no aliases/bridges, old-release
  backports or historical migration machinery. Public contract changes still need release classification/changelog.
- Update affected public docs, changelog and shipped message/View OpenAPI contracts where applicable. Add regression
  and behavior evidence using deterministic clocks/controlled interleavings, focused checks and complete `./bin/build`.
- Return upstream IDs, actual implementation commit, verification evidence, public API/repository changes and Agent OS
  composition guidance. Package tests are not actual PostgreSQL consumer qualification. Agent OS `TASK-00024` is an
  external context ID only; no consumer edits, lock changes, dependency reorder or adoption-complete assertion.
- This is a prerequisite for `v0.5.0` readiness. Signing/tagging/push/publication require separate authority.

## Current-code revalidation

Inspected current `develop` `7ca8eecfa3a8ecc6d32b7cbe6f5502bde4e9fb2b`; this is source tracing, not a fresh test pass,
release certification or consumer qualification. Relevant runtime sources were unchanged during this inspection.

| Finding | Primary repository evidence | Consequence |
|---|---|---|
| Due work excludes exact expiry and later, even with ciphertext | [`CredentialDelivery::isDueAt()`](../../../src/Domain/AccessControl/CredentialDelivery/CredentialDelivery.php) returns false at `at >= expiresAt`; all three reference repositories use it | Restarting due discovery alone cannot terminalize offline-expired work. |
| Aggregate delivery expiry already removes material and claim state without erasing history | [`CredentialDelivery::expireAt()`](../../../src/Domain/AccessControl/CredentialDelivery/CredentialDelivery.php), wrapped by all three grants' `expireDeliveryAt()` | Reuse this transition; do not invent another cleanup state machine or provider outcome. |
| Current public discovery is due-only | [`FindDueCredentialDeliveriesHandler`](../../../src/Application/AccessControl/CredentialDelivery/QueryHandler/FindDueCredentialDeliveriesHandler.php) and [`ActivationGrantRepository`](../../../src/Domain/AccessControl/ActivationGrant/ActivationGrantRepository.php), [`PasswordResetGrantRepository`](../../../src/Domain/AccessControl/PasswordResetGrant/PasswordResetGrantRepository.php), [`EmailChangeGrantRepository`](../../../src/Domain/AccessControl/EmailChangeGrant/EmailChangeGrantRepository.php) expose `findDue()`, not expired-work selection | Add an explicit read-only repository/query contract; do not weaken due semantics. |
| Reset has direct package expiry | [`ExpirePasswordResetDeliveryHandler`](../../../src/Application/AccessControl/PasswordResetGrant/CommandHandler/ExpirePasswordResetDeliveryHandler.php) loads by delivery ID within `commitTransactional()`, expires, CAS-replaces, publishes only after commit | Reuse and strengthen/revalidate this path as necessary; discovery still missing. Latest-generation fencing currently relies on the mandatory repository CAS. |
| No invitation expiry command/handler was found | Enumerated current `src/Domain/AccessControl/ActivationGrant/Command/` and `src/Application/AccessControl/ActivationGrant/CommandHandler/`; invitation worker claims only eligible current work | Add direct invitation delivery expiry, not a consumer callback or provider attempt. |
| Email authority expiry is different from delivery expiry | [`EmailChangeGrant::expireAt()` and `expireDeliveryAt()`](../../../src/Domain/AccessControl/EmailChangeGrant/EmailChangeGrant.php); [`ExpireEmailChangeHandler`](../../../src/Application/AccessControl/EmailChangeGrant/CommandHandler/ExpireEmailChangeHandler.php) expires authority and User reservation atomically | Authority expiry invalidates recoverable delivery and releases the reservation; already ciphertext-free delivery retains its existing terminal status/history. Delivery-only expiry does not release the reservation. |
| Account-state changes can strand email expiry if the handler is reused unchanged | [`User::disable()`, `delete()`, `restore()` and `expireEmailChange()`](../../../src/Domain/AccessControl/User/User.php), plus [`UserRepository::replaceLifecycleState()`](../../../src/Domain/AccessControl/User/UserRepository.php) | Lifecycle transitions preserve the reservation; current expiry requires `ACTIVE`. Complete expiry must cover reachable inactive/restored states without reactivation or unrelated writes. |
| Some direct reset expiry evidence exists, but not complete offline cleanup | [`PasswordResetDeliveryLifecycleHandlerTest`](../../../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/PasswordResetDeliveryLifecycleHandlerTest.php) covers pending boundary/repeat, missing/mismatch/CAS loss, stale generation and failure | Existing coverage is not discovery/restart proof for pending/retry/claimed work across families. |

Search scope: current `src/` grant/delivery message, handler and repository families; matching reference repositories
and selected lifecycle tests; shipped OpenAPI and delivery guide; current/relevant historical planning. No remote
branches, released binaries or consumer PostgreSQL adapters were sampled. The gap is present in the current unreleased
checkout, not merely inferred from an earlier release.

### Additional regression target from reference validation

The three test repositories' `expiredTransition()` select retry-failure reconstruction whenever the predecessor is
`claimed` and the successor has a non-null last outcome/failure. A reclaimed retry retains its previous outcome and
failure; delivery-only expiry preserves that evidence too. Thus cleanup may be mistaken for a fresh failure using a
historical time before the new claim. Countercheck this with a failing regression before changing validation; do not
weaken complete-state comparison, erase history to satisfy it, or label it a proven real-database defect.

Sources: [`InMemoryActivationGrantRepository`](../../../tests/Application/AccessControl/ActivationGrant/Repository/InMemoryActivationGrantRepository.php),
[`InMemoryPasswordResetGrants`](../../../tests/Application/AccessControl/PasswordResetGrant/Repository/InMemoryPasswordResetGrants.php),
[`InMemoryEmailChangeGrantRepository`](../../../tests/Application/AccessControl/EmailChangeGrant/Repository/InMemoryEmailChangeGrantRepository.php).
The email repository interface's summary describes terminal authority replacement, while its reference implementation
also validates issued-to-issued delivery transitions. Clarify that current contract for cleanup rather than inventing a
new save/store port.

## Accepted behavior and downstream API design

John approved complete email-change expiry rather than a parallel delivery-only handler, and asked that every case be
covered in the way that best fits the package. The initial uniform-delivery-only recommendation is superseded.
Consistency means package-owned atomic/fenced lifecycle handling, not forcing all families into the same status.
Exact new PHP names and serialized shapes remain requirement/TASK design, not a compatibility obligation.

1. Add explicit bounded expired-work discovery through existing Domain repositories and a package QueryHandler;
   `FindExpiredCredentialDeliveries` and `findExpired(at, limit)` are proposed names. Keep due discovery unchanged.
   Invitation/reset selection covers authoritative latest, issued generations with recoverable delivery in `pending`,
   `retry_pending` or `claimed` at `expiresAt <= at`, independent of due/lease time. Email selection instead covers
   authoritative latest **issued email-change authority at grant expiry**, independent of recoverable material or
   delivery status, so already-delivered/permanent-failure/backoff-expired deliveries cannot strand reservations.
2. Return a safe immutable expiry-work result including the exact grant identity needed by `ExpireEmailChange`, plus
   purpose, User/delivery identity, expiry, revision and safe delivery status. A consumer must not perform another
   lookup, infer a generation or implement lifecycle policy to choose a package command. Order by expiry instant,
   delivery ID, then purpose. New-query limit is 1–100; runner guidance is 50. Filter before ordering/limiting and
   enforce the global bound after merging bounded family reads. No credential, material, digest, destination, claim
   token or provider error is returned. Reads never mutate, commit or emit facts.
3. Add direct invitation delivery expiry with a post-commit fact; reuse reset's existing direct expiry command/handler
   and fact. Reuse/adapt `ExpireEmailChange`/`ExpireEmailChangeHandler` for full email authority and reservation expiry;
   do not add a competing email delivery-only cleanup workflow. Consumer-protected commands bind trusted actor
   provenance, exact User and delivery/grant identities and time, never credentials. Actor ID is not authorization.
4. Revalidate exact authoritative generation, ownership and expiry in each command transaction. Invitation/reset reuse
   `expireDeliveryAt()` and complete-state CAS. Email reuses `EmailChangeGrant::expireAt()` and atomically CAS-persists
   that grant and only its exact matching User reservation. Lost either email CAS rolls back both. The authority
   transition invalidates recoverable delivery; an already material-free terminal delivery retains its status/history.
   Neither family decrypts, invokes providers, increments attempts or rewrites past outcomes during expiry.
5. Complete email expiry must not depend on the User still being `ACTIVE`. Disable/delete preserve reservations, and
   restoration may return a User to active or pending activation. Adapt the narrow User reservation-expiry transition
   and its repository validation as needed to clear a matching expired reservation in every reachable account state,
   preserving account state, canonical email, password, authentication/authorization authority and unrelated fields.
   Only reservation revision/normal update metadata may change. No reactivation, canonical-email promotion or broader
   lifecycle change is authorized. The handler's authoritative expired-grant and matching-reservation checks remain.
6. Missing, stale, consumed, revoked/cancelled, already authority-expired or replaced work must never be revived or
   overwrite a different reservation/generation. Distinguish ordinary stale/no-op work from inconsistent persisted
   relationships: fail closed and surface safe evidence of unresolved work, rather than claim successful cleanup or
   silently require the consumer to repair lifecycle state. Discovery/progress design must prevent ineligible rows
   from starving supported eligible work. No historical-data repair or arbitrary corruption-reconciliation system.
7. Only actual committed transitions publish their corresponding business fact; repeats/stale commands produce none.
   Preserve existing reset/email facts and ordinary persistence-failure/rollback, command-failure/rethrow and
   post-commit publication semantics. Response loss/uncertain commit must be resolved from authoritative state after
   restart, never guessed rollback or duplicate terminalization. No new reliable-publication/audit-retention system.
8. Carry complete behavior, meaningful tests, current public documentation, schemas and consumer composition recipe
   through one bounded EPIC destination and accepted TICKET/TASK decomposition, normally one implementation PR.
   Preserve completed predecessors, Agent OS planning/lock and consumer product/provider boundaries.

## Required acceptance evidence for the eventual TASK

- Start with one meaningful failing regression for the offline-expiry discovery/terminalization gap (or the related
  reclaimed-history validation defect once reproduced); confirm the expected failure, not broken fixture setup.
- Matrix: use the family/state disposition table below at just before, exact expiry and after modeled downtime. Due
  discovery still excludes expired work; expiry discovery includes email authority cleanup even when delivery has no
  recoverable material. Both discovery surfaces remain read-only/secret-free. Cover unsupported state fail-closed paths.
- Bounds: invalid limits, per-family/global ordering, tie-breaks, mixed families, eligible rows behind more than one
  page of excluded history, multi-pass drain, and read-only rediscovery of unchanged work. Repeat cleanup is harmless.
- Persistence: authoritative generation/ownership checks; complete-state CAS rejects stale/fabricated predecessors;
  retained attempt/outcome/failure evidence with ciphertext, token, claimed time and lease all removed atomically.
  Distinguish cleanup from backoff-driven terminal failure without erasing either history or policy.
- Failure/restart: failed writes/commit roll back everything; a fresh runner rediscovers and cleans original identity.
  Model restart after commit/response loss and post-commit publication failure without duplicate facts or guessed
  rollback. No decryption/provider collaborator is required or invoked on cleanup.
- Controlled interleavings: two cleanup workers, replacement before and during cleanup, consume/cancel/revoke versus
  cleanup, stale success/retry/permanent outcomes after cleanup/replacement, delivery-only aggregate expiry before
  full email expiry, and email full expiry before any delayed delivery mutation. Also race disable/delete/restore
  against full email expiry in both winner orders; CAS loss must allow safe retry against the new state. No winner
  resurrects ciphertext, overwrites successors or loses reservation safety. After release of a reservation, prove a
  fresh email-change request succeeds with a fresh generation and that old cleanup cannot clear that new reservation.
- Safe status/message/View round trips and generated OpenAPI contract integration in the default suite. Add required
  new schemas/components to the shipped contract and update affected guides, README/schema inventory, CONTEXT and
  Unreleased changelog as applicable; do not manufacture endpoints or compatibility examples.
- Focused suites and complete `./bin/build` plus planning/documentation checks in the package runtime. Record actual
  test/assertion counts, exact owned statement coverage, warnings/skips, command/environment, tested content, process
  exit and implementation commit mapping in the TASK receipt/log/handoff. No fresh implementation gate has run yet.
- Independent review of the complete committed TASK against approved criteria and the saved full gate; post-review
  nonvisual behavioral QA for the API/recovery/race behavior. This session cannot independently accept its own work.
- Consumer-bindable or equivalent public-port package proof plus explicit real PostgreSQL qualification gaps: shared
  connection transactions, persistence/CAS fidelity, both race winner orders, rollback/restart, scheduling bounds and
  durable destruction must be qualified in the actual consumer separately. In-memory interleavings are not SQL proof.

## Family/state disposition matrix

Apply the full time boundary matrix (before/exact/after downtime) to supported states. Terminal delivery is not the
same as terminal email-change authority.

| Case | Invitation / reset | Email change |
|---|---|---|
| Pending or retry_pending with ciphertext | At expiry discover and delivery-expire; preserve attempt/history | At grant expiry discover and expire authority + matching reservation; invalidate material |
| First claim or abandoned claim | At expiry destroy material and token/claim/lease; preserve attempt evidence | Same destruction atomically with authority/reservation expiry |
| Reclaimed after prior retry failure | Preserve prior outcome/failure and new attempt count; validate cleanup distinctly from a fresh retry failure | Preserve all history while authority expiry releases reservation |
| Delivered or permanent_failure, no material | Already delivery-terminal; exclude, preserve history | Include while authority is issued and expired; release reservation without rewriting delivery outcome |
| Delivery expired by retry/backoff or prior aggregate delivery expiry | Exclude if already material-free; no new fact | Include at grant expiry while authority is issued; full expiry still releases reservation, retains delivery history |
| Invalidated by consumption/revocation | Exclude; never rewrite consumed/revoked authority or delivery history | Exclude if authority already consumed/revoked; no second authority-expiry fact |
| Already fully expired email authority | N/A: invitation/reset use delivery terminalization only | Exclude; replay is mutation/event-free |
| Superseded generation or replacement/new reservation | Exclude obsolete discovery; stale commands/outcomes cannot touch successor | Same; exact grant and reservation CAS prevent clearing a new destination, including same-email ABA |
| User disabled, deleted, enabled or restored after email request | Delivery cleanup must not require an eligible login/account state | Matching expired reservation and authority still cleaned; preserve current lifecycle state, including restoration to pending activation |
| Missing User/grant, wrong ownership, absent/mismatched reservation or corrupt state | No fabricated issuance/success; fail closed as appropriate | No unrelated reservation removal; distinguish stale work from unresolved inconsistency and provide safe failure evidence |
| Competing cleanup/delivery/lifecycle writers | Only complete-state CAS winner persists; stale worker cannot restore bytes | Grant + reservation changes share one transaction and both complete-state fences |
| Write/commit failure, uncertain commit or post-commit notification failure | Rollback or authoritative restart resolution; original identity retained, no repeated fact | Same, including neither half of grant/reservation expiry surviving a failed transaction |

## Proposed Agent OS composition recipe to finalize after implementation

Register the new expired query and direct purpose-specific expiry handlers beside current due/status handlers.
Implement all repository discovery/CAS contracts on the existing shared transactional connection. Each runner cycle
reads one bounded expired page at its deterministic current time, dispatches exact returned User/delivery IDs for
invitation/reset or User/grant IDs to `ExpireEmailChange`, with its trusted worker actor, then runs the unchanged
due-delivery pass. Email discovery must run for issued expired authority even if delivery has already finished. Wait for
synchronous cleanup to finish before another page; asynchronous scheduling must not spin on unchanged rows. Discovery
is advisory: commands reread and decide lifecycle/CAS eligibility. The runner contains no lifecycle, retry or ciphertext
policy and cleanup requires no keys/provider. Finalize names and recipe against actual accepted APIs, not this proposal.

## Resolution boundary

This decision settles complete package-owned expiry, bounds and family treatment for planning. It creates no
requirements TICKETs or implementation TASKs, selects no checkout, authorizes no execution, supplies no independent
verdict, qualifies no consumer database/adoption and grants no commit/push/PR/tag/sign/publication authority.

## Resolution

John approved the revised email treatment and explicitly requested complete case handling: “sure; please be sure we
handle all of the cases, however it makes the most sense to do it. I just want to be sure that we're not leaving
anything out”. Full package-owned email authority/reservation expiry supersedes the initial delivery-only proposal;
invitation/reset retain delivery expiry. The account-state omission found during the expanded trace is included in
that complete-treatment boundary, not deferred to consumers or a second queue. Implementation detail may adapt the
existing narrow expiry transitions/contracts; no broader account lifecycle/product behavior is approved.

Approved bounded EPIC destination: **Package-Owned Human Credential Expiry Cleanup for v0.5.0**. The
[map handoff](../human-credential-delivery-expiry-map.md#approved-epic-handoff) owns EPIC creation and subsequent accepted
TICKET/TASK decomposition. No implementation, full-gate result or independent acceptance exists at this checkpoint.
