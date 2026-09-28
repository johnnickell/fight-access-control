---
id: TICKET-00014
epic: EPIC-00009
title: Preserve credential authority across migration and upgrades
status: ready-for-agent
---

# Preserve credential authority across migration and upgrades

## Problem and Outcome

The replacement recoverable Agent contract changes APIs and persisted invariants. Fabricated historical correlation,
mixed legacy/new writers, changed request normalization or a database restore behind external sink effects could
reissue credentials or revive stale authority. Consumers need an explicit compatibility contract and migration route
that preserves existing authentication and makes unsupported deployments fail closed.

This is the third approved requirement area under [EPIC-00009](../epics/00009-EPIC.md), targeting `v0.5.0`. It owns
package compatibility behavior, migration/public guidance and complete evidence traceability. It does not execute
consumer migrations, qualify an untested consumer, release the package or take over the issuance/delivery tests owned
by [TICKET-00012](00012-TICKET.md) and [TICKET-00013](00013-TICKET.md).

## Use Cases

| Actor and trigger | Commands/service intent | Queries | Events and expected effects |
| --- | --- | --- | --- |
| Consumer maintainer upgrades existing Agent storage/composition | Consumer-owned migration tools; no package migration Command | Safe Agent/operation reads identify legacy/non-recoverable state | No manufactured issuance/delivery facts or automatic rotation/redelivery. Existing active credentials still authenticate; revoked Agents stay terminal. |
| Authorized maintainer needs recovery for a known legacy Agent | TICKET-00012's explicitly authorized new rotation against current credential | Original legacy status, then new operation status | Only that new operation creates recovery binding/work. Ambiguous historical provisioning requires reconciliation, not automatic provision or name/audit inference. |
| Consumer rolls out a new compatible cohort | Consumer-owned deployment/admission controls | Capability/contract-version readiness and safe status | Missing capabilities and incompatible/restarted old writers cannot mutate new authority. No new business Event is introduced by deployment. |
| Caller retries an old or tombstoned operation after upgrade | Original request/key through replacement service | Authorized operation lookup/resolution | Recorded canonical version retains equality/conflict semantics; unknown versions deny without new issuance or secret access. |
| Consumer restores/rolls back after external sink acceptance | Consumer-owned quiesce, restore and reconcile tools | Authoritative operation/receipt/order evidence | Preserve deduplication and ordering; incompatible restoration remains unavailable rather than duplicate issuance or revive retired state. |
| Integrator qualifies persistence, authorization and sink adapters | Run public consumer-bindable behavior suites and deployment rehearsals | Evidence inventory and gaps, not a new product Query | Distinguish package proof from real adapter proof and consumer activation/use authority. No publication/upgrade follows merely from a passing package suite. |

There is no new package migration, release, activation or launch Command, Query or Event. Runtime operation inputs,
status and safe facts are owned by TICKET-00012/00013. Deployment tools are a consumer concern, not a reason to make
Domain/Application depend on an Adapter layer.

## Existing Data and Breaking API Contract

- Replace raw-return provision/rotation signatures without an additive non-recoverable bypass. Remove or explicitly
  reject old calls; never infer keys, destinations or authority. Every retained lifecycle entry point obeys the new
  fences/cancellation contract. TICKET-00012/00013 own runtime enforcement and their focused proof.
- Preserve existing Agent IDs, active credentials and authentication envelopes. Upgrade alone neither rotates nor
  redelivers. Explicitly mark absent historical correlation as legacy/non-recoverable; safe schema/version markers
  do not claim an issuance outcome. Revoked Agents remain terminal.
- Never backfill operation/request/delivery/receipt bindings from display names or audit rows, and never copy an
  authentication envelope into delivery storage. Known active Agents require separately authorized rotation for new
  recovery state; ambiguous old provision requires human reconciliation. This exception is not routine recovery approval.
- Preserve the single-active-credential, immediate replacement, terminal revocation and authentication nonce/current-
  authority invariants in [ADR 0004](../adr/0004-agent-hmac-credential-lifecycle.md) and
  [WF-002](../wayfinder/tickets/WF-002-agent-credential-revocation-lifecycle.md). Permission-tier administration and
  the separate MCP integration are not changed.

## Deployment, Cohort and Restoration Requirements

1. **Inventory and rehearse:** inventory active/legacy Agents, pending delivery, keys, lifecycle/authorization writers
   and existing external sink effects. Rehearse backup/restore using the consumer's actual migration/deployment tools.
   Quiesce/fence old issuance, rotation/revocation, delivery and authority writers before switching; drain or fence
   already-admitted calls. Maintenance cannot leave old authority writers bypassing new fences.
2. **Prepare compatible storage/composition:** add operation/request versions, delivery/receipt/claim/admission data,
   destination/authorization fences and uniqueness/version constraints while preserving authentication envelopes.
   Compatible repositories and authorization participation share the package connection; cipher/sink capabilities
   honor the accepted contracts. Missing storage/capabilities reject startup/admission, never fall back to legacy.
   A specific schema/DDL/ORM choice remains consumer-owned.
3. **Switch one controlled cohort:** mixed legacy/new lifecycle or delivery writers against shared authority are
   unsupported. Persisted contract-version checks deny incompatible/restarted workers. Fence old binary credentials/
   access at storage or a trusted admission boundary: new-code-only checks cannot stop an old binary. Compatible
   canonicalizer versions must agree on new-key creation rules. Enable scheduled discovery and only enable sensitive
   admission after migration, conformance, sink fencing and key readiness. No zero-downtime guarantee is made.
4. **Preserve historical request semantics:** look up the authorized scoped key before choosing a canonicalizer,
   including retained tombstones. Persist version plus sufficient safe canonical request, keep old readers for the
   retained key lifetime, and never rewrite bindings under new normalization. Equivalent/conflicting old requests
   stay equivalent/conflicting; unknown versions deny without mutation/disclosure/new-key fallback. A package upgrade
   cannot silently change destination meaning or make expired/completed keys reusable.
5. **Prefer forward repair after new effects:** never roll back to legacy writers or drop operation/fence evidence
   after a new-contract mutation. A compatible rollback preserves the exact persisted contract, historical readers
   and fences with writers quiesced. Restoring an older database alone is unsafe after sink acceptance. Reconcile
   receipts, deduplication tombstones and high-water evidence before admission; otherwise remain unavailable for
   reconciliation. Before any new-contract state/effects, a fenced legacy cohort may resume only after proving none
   exist. Backup restoration must not reset destination order or resurrect retired keys.

## Permissions, Validation and Public Guidance

Migration/deployment authority is consumer-owned; no package Permission names or runtime human-approval framework
are added. Every runtime status, retry, discovery, admission, acknowledgement and activation/use decision retains
current authorization. Tooling/maintenance permission is not permission to disclose or activate a credential.

Validate contract versions, retained canonical-request meaning, data markers and required capabilities through
observable outcomes. Unsafe or unknown state rejects without fabricated history or secret access. Real consumer
conformance must prove authority-writer participation under concurrency, including non-HTTP paths; external cached
allow decisions and partial writer upgrades cannot pass by documentation alone.

Public documentation must identify `v0.5.0` as the planned breaking replacement, describe removed/rejected APIs and
new persistence/authorization/sink obligations, and distinguish confirmed issuance, publication warning, credential
delivery, activation and launch/use authority. Describe finite defaults and optional validated overrides supplied by
TICKET-00012/00013, clear retryable new-work capacity outcomes and preservation of existing recovery. Do not require
manual configuration or extra approval for routine operation; do not generalize the D3 exception to revocation or
other handlers. Concrete values and implementation choices must be documented and tested before implementation
acceptance. Reconcile README, CONTEXT, project guidance, migration documentation and changelog against the implemented
public contract without rewriting historical completed records or claiming release/adoption.

## Scenario Ownership and Evidence Traceability

This maps **every row** in the [proposal's required behavior matrix at `ecd849e`][proposal] to a behavior owner.
It does not reduce that row's detailed requirements. Labels I1–I7 and D1–D7 refer to acceptance items in
TICKET-00012 and TICKET-00013, not new planning IDs. M1–M5 are the acceptance items below. A shared row requires
both sides' proof; tests stay with their business owner rather than being deferred to this TICKET.

| Proposal scenario | Owning acceptance and required evidence |
| --- | --- |
| Normal provision and rotation | TICKET-00012 I1: one mutation/audit and prepared delivery; TICKET-00013 D1/D3: actual delivery is a separate confirmed outcome. |
| Commit succeeds, response is lost, then caller/service restarts | TICKET-00012 I2: same-key original outcome, no repeated generation/mutation, including original rotation predecessor. |
| Indeterminate issuance commit, for both provision and rotation | TICKET-00012 I3: both committed and rolled-back cases resolved from storage after restart. |
| Indeterminate admission or delivery-outcome commit | TICKET-00013 D3: no unconfirmed materialization; current receipt reconciliation, not blind redelivery. |
| Success event publication throws after commit, for both provision and rotation | TICKET-00012 I4: ratified D3 returns confirmed metadata with typed sanitized warning, even when failure publication also fails. |
| Both publishers fail and caller never retries | TICKET-00012 I4 plus TICKET-00013 D1: terminate caller; restarted authorized scheduler discovers/delivers, with stable facts and no duplicate audit. |
| Pre-commit audit, encryption or persistence failure | TICKET-00012 I3: no partial Agent/operation/delivery/audit or false committed success. |
| Same key, changed name/target/predecessor/kind/destination | TICKET-00012 I2: conflict without mutation/disclosure; canonical equivalence tested separately. |
| Wrong caller/namespace, revoked authorization, expired delegation | TICKET-00012 I5 plus TICKET-00013 D1/D2: current authority on new work, retry, status, discovery and delegated original-scope resolution. |
| Equal opaque operation IDs across scopes at one sink | TICKET-00012 I5 plus TICKET-00013 D4: distinct delivery IDs, rejected unauthorized ownership, monotonic cross-scope reassignment order. |
| Caller/delegation/destination authority changes around admission | TICKET-00013 D2: all temporal interleavings, expiry and revoke/regrant ABA; real consumer activation/use conformance also required. |
| Authorization and lifecycle writers share the transaction fence | TICKET-00012 I5 plus TICKET-00013 D2/D5: public transaction-conformance scenarios and real adapter runs, not merely in-memory ordering. |
| Concurrent identical and conflicting requests | TICKET-00012 I2: one scoped-key winner, and at most one rotation successor across keys. |
| Store unavailable; store accepts then response is lost; delivery outcome commit fails | TICKET-00013 D3: recoverable original ID/bytes, no delivered result without receipt or fallback issuance. |
| Concurrent delivery retry and lease takeover | TICKET-00013 D3: advancing claim fences, stable delivery ID/bytes and no stale acknowledgement. |
| Different operations deliver out of order | TICKET-00013 D4: delay before/after newer sink acceptance, cross-slot rotation and cross-Agent/scope reassignment; inert old material cannot authorize use. |
| Receipt or idempotency binding mismatch | TICKET-00013 D4: every tuple field/bytes mismatch and missing/unverifiable receipt reject without delivery/activation. |
| Revocation/supersession before and during invocation | TICKET-00013 D2/D5: retired credentials deny admission/completion and original status never resolves to a newer secret. |
| Delivered-secret loss, retention expiry and replay after tombstoning | TICKET-00013 D6 plus TICKET-00012 I6: no re-read/revival/duplicate issuance; retained sink and operation evidence withstand delayed replay. |
| Decryption/key access fails after issuance | TICKET-00013 D6: temporary recovery, permanent failure, corrupt/swapped material and rewrapping preserve the original binding without envelope fallback. |
| Every retained lifecycle entry point races delivery | TICKET-00013 D5 plus TICKET-00012 I7: atomic cancellation through all paths; old signatures reject instead of inventing bindings. |
| Existing-Agent upgrade and incompatible consumers | This TICKET M1/M2: preserved authentication, explicit legacy state, no manufactured recovery, incompatible/restarted writers fenced. |
| Canonical-request versions across upgrade/restart | This TICKET M3 with TICKET-00012 I6: old equivalence/conflict persists, unknown versions deny, compatible creation cohort enforced. |
| Rollback/restore after external acceptance | This TICKET M4 with TICKET-00013 D4/D6: no reset/resurrection, receipt/tombstone reconciliation or unavailable state; real restore rehearsal required for adoption. |
| Safe representations and sensitive-value lifetime | TICKET-00012 I7 plus TICKET-00013 D6: safe serializable/debug/event/audit/failure surfaces, fixed sensitive destination and no secret-read capability. |

Additional upstream-ratified requirements: TICKET-00012 I1/I4 and TICKET-00013 D2/D4 prove the D3 separation of
issuance/delivery/activation/launch authority. TICKET-00012 I6 and TICKET-00013 D7 prove D4 default/override, capacity/
existing-recovery and cleanup behavior. M5 verifies these are included in the final traceability/evidence inventory.

## Acceptance Evidence

- [ ] **M1 — Existing-Agent/API compatibility:** package behavior tests preserve active authentication and terminal
      revocation across upgrade without manufactured operation/delivery state. Explicit new authorized rotation is
      the recovery path for a known legacy Agent; ambiguous provision cannot auto-create another Agent. Public API
      migration guidance matches the replaced signatures and preserved authentication envelope.
- [ ] **M2 — Contract cohorts:** test missing capability/contract storage and incompatible/restarted consumers deny
      mutation/admission without fallback. Document all lifecycle and authorization writers plus the necessary
      storage/trusted-boundary fencing of old binaries. Published conformance obligations include real writer races;
      do not claim a new-code version check fences an old binary by itself.
- [ ] **M3 — Canonical upgrades:** package tests retry stored and tombstoned keys after normalization/version changes:
      old equivalent/conflicting requests retain their meaning, unknown versions deny and compatible new-key creation
      versions agree. Neither destination reinterpretation nor cleanup makes an old key reusable.
- [ ] **M4 — Restoration safety:** observable package/conformance tests demonstrate that stale restored state cannot
      silently authorize duplicate issuance, reset order or resurrect retired keys after external acceptance. Document
      compatible reconciliation prerequisites, forward repair and unavailable outcomes. Actual schema, mixed-version
      rollout and backup/restore rehearsals are mandatory consumer adoption evidence, not product tests of migration files.
- [ ] **M5 — Complete evidence and guidance:** maintain the matrix above with actual test/receipt references as work
      completes; every scenario has an owner, result and explicit gap where proof is missing. TICKET-00012/00013
      retain their own behavior tests and public consumer-bindable suites. Guidance covers D1–D4, migration/deployment
      order, secret-safe outcomes, bounded defaults and supported consumer obligations without claiming implementation,
      publication or adoption from planning. No real-adapter qualification is inferred from a mock or in-memory fake.
- [ ] Compatibility tests assert outcomes, not file/configuration text. Consumer-owned migration/deployment tools
      provide direct rollout/restore proof before consumer qualification. The full package `./bin/build`, including
      exact statement coverage, and `./bin/planning-check` pass for implementation; current documentation links pass.
      Preserve warnings, failures and incomplete evidence rather than changing the acceptance scope.

## Evidence Boundaries and Sequencing

TICKET-00012 owns issuance/resolution tests and persistence/authorization conformance; TICKET-00013 owns delivery,
sink and lifecycle/admission tests/conformance. This TICKET depends on both public contracts for final compatibility
and migration guidance; draft those requirements alongside their design so migration cannot be an afterthought.
There is no permissible unsafe intermediate release of one part of the protocol.

Package acceptance needs executable owned behavior tests and reusable consumer-bindable conformance contracts with
traceable coverage. Supporting any actual consumer additionally requires real persistence, authority-writer and sink
runs proving atomicity, uniqueness, generations, receipt order and activation/use authority. PostgreSQL behavior is
not proven by in-memory fixtures. Consumer-owned schema/deployment/restore rehearsals are separately executed in that
repository; this record defines their required evidence and handoff, not permission to perform them. Do not mark
consumer qualification complete merely because package criteria pass. Agent OS adoption, other R1 prerequisites and
R2/R3 evidence remain separate, and its TASK-00138 stays unchanged.

## Exclusions

No production Adapter layer, concrete schema/ORM/SQL or provider selection, consumer migration execution, Agent OS
Composer/vendor change, enrollment/broker implementation, HTTP contract, generic migration framework, tag/release/
publication, deployment or TASK-00138 closure. No extra routine human approval, unrestricted secret retrieval,
legacy raw-return escape path or automatic credential backfill. New generic security Permissions and business events
are N/A: this TICKET specifies compatibility and consumer qualification, not a new authorization policy or workflow.

## TASK Ownership and Readiness

John approved this TICKET's five-TASK split on 2026-09-27. Each slice normally owns one independently reviewable PR;
compatibility behavior tests stay with their owner rather than moving into final documentation. These parented
features/guidance records do not use the standalone bug/chore `kind` exception.

| Slice | TASK | Blockers | Acceptance ownership |
| --- | --- | --- | --- |
| A — Existing-Agent compatibility | [TASK-00055](../tasks/00055-TASK.md) | TASK-00047, TASK-00048 | M1: preserved authentication, explicit legacy state and authorized new rotation without invented history. |
| B — Contract cohorts | [TASK-00056](../tasks/00056-TASK.md) | TASK-00048, TASK-00052, TASK-00053 | M2: persisted compatibility/capability guards across actual writers, plus explicit external old-binary fencing obligations. |
| C — Canonicalization upgrades | [TASK-00057](../tasks/00057-TASK.md) | TASK-00056, TASK-00047 | M3: recorded historical semantics through provision/rotation/status and compatible new-key creation cohorts. |
| D — Restoration safety | [TASK-00058](../tasks/00058-TASK.md) | TASK-00056, TASK-00057 | M4: observable guards/conformance for reconciled versus unreconciled restored state, not automatic rollback detection. |
| E — Migration guidance and traceability | [TASK-00059](../tasks/00059-TASK.md) | TASK-00055, TASK-00056, TASK-00057, TASK-00058, TASK-00049, TASK-00054 | M5: implementation-aligned migration route and all 25 proposal scenarios plus D3/D4 additions with real result references and explicit gaps. |

These are downstream dependencies on real issuance/delivery and compatibility paths; no predecessor is made dependent
on this TICKET's final evidence aggregation. TASK-00049/00054 retain issuance/delivery conformance ownership. The
scenario matrix above remains the complete requirement map; TASK-00059 adds verified results as implementation
completes, not invented passes during planning.

TASK-00056/00058 must distinguish package checks from consumer-controlled storage/admission fences and externally
reconciled evidence. A new-code guard cannot stop unchecked old binaries or detect arbitrary database rollback;
unsupported integration remains unsupported. Consumer migrations, real writer/sink tests, activation/use authority
proof and rollout/restore rehearsals stay separate adoption obligations, not permission granted by this plan.

## Progress

TASK-00055–00059 are recorded as `ready-for-agent` with unfinished blockers. They are planned, not executable yet;
TASK-00046 remains first ready across this work. All acceptance boxes stay open and no TASK is in progress. Concrete
public representations, compatibility guards and reconciliation semantics must be designed/proved within their
owning slices before acceptance, without weakening accepted requirements.

Before allocation, refreshed local live/archive inventory at unchanged HEAD
`92d1de82a7833cc6dafb90eccea0d132f0e3cd77`: live TASK IDs ended at 00054, archive held only its README and no duplicate
Agent compatibility/migration TASK was found. Orders 55–59 preserve the existing portfolio. No remote inventory was
queried; prior uncommitted work and unrelated TASK-00035 remain preserved.

EPIC-00009's three TICKETs now all have approved TASK decompositions. This completes planning, not implementation,
release or adoption. Implementation requires separate authority and explicit branch/worktree selection. At the
planning-only checkpoint before publication authorization, no product build, runtime/consumer qualification,
migration/restore execution, commit, publication, dependency upgrade or Agent OS TASK-00138 closure was claimed. Planning verification is recorded in the parent EPIC after regeneration.

[proposal]: https://github.com/johnnickell/fight-agent-os/blob/ecd849ec8817b460dbaf170df84458c56c40a0d5/docs/engineering/ACCESS_CONTROL_AGENT_OPERATIONS_PROPOSAL.md#required-behavior-evidence-before-package-acceptance

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 55 | [TASK-00055](../tasks/00055-TASK.md) | Preserve existing Agent authority through upgrade | ready-for-agent |
| 56 | [TASK-00056](../tasks/00056-TASK.md) | Reject incompatible credential-operation cohorts | ready-for-agent |
| 57 | [TASK-00057](../tasks/00057-TASK.md) | Preserve operation-key meaning across canonicalization upgrades | ready-for-agent |
| 58 | [TASK-00058](../tasks/00058-TASK.md) | Fail closed on unreconciled credential-state restoration | ready-for-agent |
| 59 | [TASK-00059](../tasks/00059-TASK.md) | Complete migration guidance and evidence traceability | ready-for-agent |
