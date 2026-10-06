---
id: TICKET-00020
epic: EPIC-00012
title: Clean up expired human credential work after downtime
status: in-progress
---

# Clean up expired human credential work after downtime

## Problem and Outcome

A consumer offline through credential expiry cannot use due-work discovery to clean stranded invitation/reset delivery.
Email-change delivery can already be terminal while its issued authority and User reservation still require expiry;
changed account state must not strand that reservation either.

Provide one complete package-owned recovery journey: a protected consumer runner discovers bounded safe expired work
and dispatches purpose-specific package commands, without decryption, provider calls or copied lifecycle policy.
Invitation/reset expire delivery; email change expires authority and its exact reservation atomically. Preserve current
identity, account/security state, history, retry policy and stale-worker fences.

John approved this single cohesive requirement area under [EPIC-00012](../epics/00012-EPIC.md).
[WF-025](../wayfinder/tickets/WF-025-human-delivery-expiry-contract.md) remains the accepted family/state authority.
Its entire matrix and required evidence are mandatory, not illustrative. This TICKET groups discovery, terminalization,
recovery, contracts and evidence for one operator journey; it is not an implementation TASK or execution authorization.

## Use Cases

New operation labels describe intent, not finalized PHP names. Reuse the named existing reset/email commands and facts.

| Actor and trigger | Commands | Queries | Events | Observable outcome and side effects |
| --- | --- | --- | --- | --- |
| A protected consumer runner starts/restarts after downtime | N/A: discovery is read-only | New expired-work query through the three Domain grant repositories | N/A: no mutation, commit or fact | Return a bounded deterministic secret-free page sufficient to dispatch exact purpose-specific cleanup, including email authority with terminal delivery. Due discovery remains unchanged. |
| The runner dispatches an expired invitation | New direct invitation delivery-expiry command | Authoritative repository rereads inside the command transaction; no new public lookup required | Invitation delivery-expired fact after a real committed transition | Expire recoverable delivery and remove ciphertext plus claim token/time/lease atomically, retaining identity and attempt/history evidence. |
| The runner dispatches an expired password reset | Reuse/adapt `ExpirePasswordResetDelivery` | Same transactional eligibility rereads | Existing `PasswordResetDeliveryExpired` after a real committed transition | Apply the same delivery-only cleanup boundary without changing grant authority policy or retry/backoff. |
| The runner dispatches an expired email change, including finished delivery or an inactive User | Reuse/adapt `ExpireEmailChange` | Authoritative grant and User/reservation rereads inside one transaction | Existing `EmailChangeExpired` after a real committed transition | Expire authoritative issued grant and clear only its exact matching reservation; invalidate recoverable delivery, retain material-free terminal status/history, and preserve current account/security state. |
| Work repeats, becomes stale, or races another lifecycle/delivery writer | Same exact commands | Read-only rediscovery and authoritative transactional rereads | No success fact for unchanged/stale work | No unchanged write, duplicate terminalization, successor overwrite, unrelated reservation removal or resurrection; safe current work remains recoverable. |
| Storage fails, a response is lost, or publication fails after commit | Same commands after authoritative restart resolution | Rediscover original work or read authoritative state | Existing safe command-failure/rethrow semantics; success facts only after actual commit | Rollback leaves no partial destruction/authority change; uncertain commit resolves from persisted state, not presumed rollback. Committed cleanup survives notification failure without a guaranteed event replay. |

No public authentication, transport, scheduler or corruption-repair command is introduced. Queries are not hidden commands;
internal rereads revalidate advisory work rather than require consumer generation inference or lifecycle decisions.

## Discovery Requirements

- Add explicit expired-work selection through existing Domain grant repositories and one QueryHandler. Proposed names
  `FindExpiredCredentialDeliveries` and `findExpired(at, limit)` remain downstream API design. Do not weaken `findDue()`.
- Invitation/reset eligibility is latest authoritative issued generation, recoverable `pending`, `retry_pending` or
  `claimed` delivery, and delivery expiry at or before the supplied instant. Due time and lease do not postpone expiry.
- Email eligibility is latest authoritative issued grant at or past grant expiry, regardless of delivery status/material.
  Include delivered, permanent-failure and delivery-expired work; consumed/revoked or fully authority-expired work is excluded.
- Filter eligibility before ordering/limiting. Order each family and the merged result by expiry instant, delivery ID,
  then purpose; bounded family reads must produce a globally bounded page. Validate limits 1–100; runner guidance is 50.
  Supported eligible work behind more than one page of excluded history must not starve. Repeat reads may return the
  same unchanged work; multi-pass cleanup must drain supported eligible work without busy-spinning on unchanged rows.
- Return immutable safe work containing purpose, exact User/delivery identity, the grant identity needed for email
  expiry, expiry, revision and safe delivery status. It must support command selection without a consumer lookup or
  generation guess. No credentials, ciphertext/material, digests, destinations, claim tokens or provider errors.
- QueryHandler reads Domain repositories only; no mutation, persistence commit, business fact or secret collaborator.

## Transactional Expiry Requirements

- Inside each command transaction, revalidate exact authoritative generation, User ownership, expiry and current
  complete expected state. Commands bind trusted actor provenance, exact User and delivery/grant identity and time;
  neither discovery nor a supplied actor ID grants mutation authority.
- Invitation/reset reuse `expireDeliveryAt()` and complete-state repository CAS. Do not invent grant authority expiry
  for these delivery-only commands. Eligible cleanup commits terminal state, material destruction and claim
  token/claimed-at/lease removal together; no partial write or destruction survives rollback.
- Email reuses `EmailChangeGrant::expireAt()` with only its exact matching User reservation. Both repository CAS writes
  share one `TransactionalUnitOfWork`; either CAS loss rolls back both. Recoverable delivery is `invalidated` by this
  authority transition; ciphertext-free terminal delivery retains its existing status/history, not a forced `expired` status.
- Narrowly adapt User reservation expiry and its repository validation for every reachable account state, including
  disabled, deleted, enabled and restored-to-pending activation. Preserve lifecycle state, canonical email, password,
  authentication/authorization authority and unrelated fields. Only reservation revision and normal update metadata
  may change. No reactivation or canonical-email promotion; delivery cleanup does not require login eligibility.
- Preserve immutable delivery ID, attempt count, last attempt/outcome and safe failure history. Cleanup neither
  decrypts nor invokes providers, increments attempts, fabricates a retry failure or changes lease/retry/backoff policy.
  Countercheck WF-025's suspected reclaimed-history reference-validation defect with a meaningful failing regression
  before repair. Keep complete-state CAS and history; clarify the email repository's current authority/delivery replacement
  contract rather than introduce a save/store port or falsely claim a demonstrated real-database defect.
- Superseded, consumed, cancelled/revoked, already-terminal or newer state must not be revived or overwritten. Preserve
  stale-worker fencing against delayed delivered/retry/permanent outcomes. Old email cleanup cannot clear a successor
  reservation, including same-email ABA; reservation release permits a fresh request with a fresh generation.
- Distinguish ordinary missing/stale/no-op work from inconsistent persisted relationships. Fail closed with safe evidence
  of unresolved work instead of fabricated cleanup success, unrelated reservation removal or implicit consumer repair.
  Freeze bounded progress/failure mechanics in TASK design; no arbitrary corruption-reconciliation infrastructure.
- No unchanged writes or success facts for repeats/no-ops/stale commands. Real-transition facts follow successful commit.
  Preserve ordinary safe `CommandFailedEvent`/original-throwable rethrow and post-commit publication semantics, not
  EPIC-00009's scoped issuance-warning exception. Notification failure does not undo committed cleanup or guarantee
  later publication; no new reliable-publication system.
- After response loss/uncertain commit, resolve from authoritative state on restart. Cover committed and rolled-back
  outcomes separately; never infer rollback from missing events or terminalize/publish the same transition twice.

## Permissions, Validation and Public Contracts

Consumers guard every query/dispatch entry point they expose, including workers, CLI, API and direct bus. Actor identity
is provenance only. Package ownership/generation/expiry checks and CAS remain mandatory regardless of consumer authorization;
no new managed Permission, generic actor-only authorization port or consumer-specific policy name is introduced.

Use current immutable serializable Command/Query/Event DTO contracts, canonical array round trips, required-data validation,
registered Common handler interfaces and immutable safe results. Validate IDs, timestamps, purposes/statuses and query bounds
through current package values/contracts. Invalid or unsupported input cannot mutate or disclose secrets. New PHP names,
constructor signatures, serialized shapes and progress/failure representation remain bounded TASK design; any genuine
product ambiguity returns for a decision instead of silently changing accepted behavior.

Update affected public guides, README/schema inventory, CONTEXT, Unreleased changelog and shipped message/View OpenAPI
components, with round-trip/generated-schema integration in the default suite. Apply
[ADR 0007](../adr/0007-openapi-schema-metadata-distribution.md) and
[ADR 0008](../adr/0008-openapi-payload-contract.md), not new endpoints, status codes or transport error schemas.
Repository API changes and public release classification must be documented. Under
[ADR 0011](../adr/0011-pre-v1-current-contract-only.md), callers/tests describe only the current contract; no compatibility
aliases, old readers or historical migrations/backfills.

## Required Matrix Coverage

These are required probes, not executed evidence. Map each row to actual tests in the eventual TASK receipt, including
before/exact/after expiry and modeled downtime for supported states. Preserve the complete
[WF-025 matrix](../wayfinder/tickets/WF-025-human-delivery-expiry-contract.md#familystate-disposition-matrix) when refining tests.

| ID | Case | Required disposition/evidence |
| --- | --- | --- |
| M01 | Pending or retry-pending with ciphertext | Invitation/reset delivery expiry; email authority/reservation expiry with material invalidation; preserve history. |
| M02 | First or abandoned claim | Remove material, token, claim time and lease atomically at expiry; no attempt increment. |
| M03 | Reclaimed claim after prior retry failure | Retain old outcome/failure plus current attempt evidence; validate cleanup distinctly from a new retry failure. |
| M04 | Delivered/permanent-failure material-free delivery | Exclude invitation/reset; include expired issued email authority, release reservation and retain terminal delivery history. |
| M05 | Delivery already expired through backoff or aggregate expiry | Exclude material-free invitation/reset; still complete issued email authority/reservation expiry without history rewrite. |
| M06 | Invalidated through consumption/revocation | Exclude terminal authority; no resurrection, history rewrite or second expiry fact. |
| M07 | Fully expired email authority | Exclude discovery; replay writes/publishes nothing. Invitation/reset remain delivery-only. |
| M08 | Superseded generation/replacement/new reservation | Obsolete work is excluded; stale cleanup/outcomes cannot affect successor, including same-email ABA. |
| M09 | User disabled/deleted/enabled/restored after request | Cleanup preserves account/security state in every reachable state, including pending activation; release allows a fresh request that old cleanup cannot clear. |
| M10 | Missing User/grant, wrong ownership, absent/mismatched reservation or corrupt state | No fabricated success or unrelated removal; distinguish ordinary stale work from unresolved inconsistency with safe fail-closed evidence. |
| M11 | Competing cleanup/delivery/lifecycle writers | Only complete-state CAS winners persist; email grant/reservation share atomic fences. Exercise both winner orders. |
| M12 | Failed write/commit, uncertain commit, response loss or notification failure | All-or-nothing state/destruction and authoritative restart resolution; original identity/history retained, no duplicate terminalization facts. |

## Acceptance Evidence

- [ ] E1: Secret-free/read-only discovery proves invalid limits, eligible filtering before limiting, per-family/global
      ordering and tie-breaks, mixed pages, eligible work behind excluded history, unchanged rediscovery and multi-pass
      drain. Due discovery still excludes expired work and retains existing retry/lease behavior.
- [ ] E2: M01–M03 prove before/exact/after expiry, material/claim destruction, unchanged immutable identity/attempt/history,
      no key/provider collaborator, and a reproduced regression before any reclaimed-history validation repair.
- [ ] E3: M04–M07 prove the difference between terminal delivery and issued/terminal authority; email reservations are
      not stranded by finished delivery. Repeats do not write or publish facts and terminal history is preserved.
- [ ] E4: M08–M10 prove exact ownership/generation/reservation safety, every reachable User state, fresh requests after
      release, no same-email ABA and safe stale/inconsistent-state distinctions without account/security changes.
- [ ] E5: M11 uses deterministic clocks and controlled interleavings, no sleeps/providers: two cleanup workers,
      replacement before/during cleanup, consume/cancel/revoke, delayed success/retry/permanent outcomes, delivery-only
      expiry versus full email expiry, and disable/delete/restore versus cleanup, with both winner orders and safe
      retry after CAS loss. Complete-state validation rejects stale/fabricated predecessors; no successor or bytes revive.
- [ ] E6: M12 proves failed writes/commit rollback, restart rediscovery, both uncertain-commit outcomes, committed
      response loss and post-commit publication failure. Assert persisted outcomes, not merely invocation order;
      preserve original identity, no partial email expiry and no repeated terminalization fact.
- [ ] E7: Public message/View round trips, handler registration and generated OpenAPI integration run in the default
      suite. Affected documentation, changelog and schema inventory match current accepted APIs/repository obligations.
- [ ] E8: Focused checks, planning/documentation checks and complete `./bin/build` pass in the package runtime with exact
      owned statement coverage. Record actual test/assertion counts, coverage, warnings/skips, command/environment,
      tested content, process exit and implementation commit mapping. Independent technical review and post-review
      nonvisual behavioral QA challenge the complete committed capability.
- [ ] E9: Consumer-bindable or equivalent public-port package proof and the handoff supply upstream EPIC/TICKET/TASK IDs,
      implementation commit, verification, API/repository changes and the Agent OS composition recipe. Explicitly name
      unexecuted real PostgreSQL qualification: shared connection transactions, persistence/CAS fidelity, race winner
      orders, rollback/restart, scheduling bounds and durable destruction. Modeled package proof is not adoption proof.

## Dependencies, Sequencing and Exclusions

The selected [Wayfinder map](../wayfinder/human-credential-delivery-expiry-map.md) has one approved EPIC destination;
its decisions/EPIC phase is complete. John approved this sole TICKET decomposition and its single implementation
slice, now [TASK-00072](../tasks/00072-TASK.md). TASK decomposition is accepted and complete; expected delivery is one
complete independently reviewable PR, not layer-only or detached verification TASKs. Before
implementation ask John for main-checkout versus isolated-worktree placement and preserve unrelated work. The Board
owns execution priority. Reuse completed [TICKET-00007](00007-TICKET.md) capabilities and current supported transactional
contracts; do not reopen that TICKET, original delivery predecessors or EPIC-00009.

Production stays Domain/Application under [ADR 0001](../adr/0001-domain-application-package-boundary.md); verification uses
[ADR 0002](../adr/0002-single-quality-gate.md). Consumers own production repositories/SQL/DDL, transport, keys/providers,
scheduler/runtime and actual persistence qualification. The composition recipe must use bounded expired discovery,
exact purpose-specific dispatch and unchanged due work; synchronous cleanup precedes another page, while asynchronous
scheduling must not spin on unchanged rows. Discovery is advisory; package commands own lifecycle policy.

Agent OS `TASK-00024` is an external blocked-consumer context ID, not a local dependency or completion claim. No Agent OS
source/planning edit, Composer-lock/dependency change, consumer product/email-change wiring, production Adapter,
another queue/retry state machine, cryptographic operation, arbitrary corruption repair, historical compatibility,
old-release backport/migration/backfill, destructive reset, reliable-publication infrastructure or consumer adoption is
included. Implementation/local verification, review and applicable behavioral QA are a `v0.5.0` prerequisite, not
commit/push/PR/merge, signing/tagging, release/publication or deployment authority.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 72 | [TASK-00072](../tasks/00072-TASK.md) | Discover and clean expired human credential work | in-progress |
## Progress

John approved the single-TICKET proposal after EPIC-00012's final scope confirmation. This record retains the complete
WF-025 matrix as explicit M01–M12 requirements with unchecked acceptance evidence. John subsequently approved one
complete implementation TASK, now [TASK-00072](../tasks/00072-TASK.md), carrying all M01–M12/E1–E9 requirements.
The full TASK decomposition is accepted and complete. John explicitly selected TASK-00072, chose the main checkout
and requested inclusion of pending planning changes in the implementation commit. At the pre-publication checkpoint,
TASK-00072 implements the complete recovery boundary; focused checks pass 206 tests / 2957 assertions and `./bin/build`
passes 1993 tests / 37644 assertions with exact 6841/6841 product statements (971/971 changed-production statements).
The TASK owns every M/E/C mapping, saved failure chronology, input manifest and actual commit handoff. Independent
review and post-review behavioral QA remain pending, so acceptance evidence and TASK/parent completion remain open.
PostgreSQL qualification, consumer adoption and publication remain separate; no independent acceptance is claimed.
