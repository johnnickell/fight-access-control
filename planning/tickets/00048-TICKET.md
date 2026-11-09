---
id: TICKET-00048
epic: EPIC-00017
title: Qualify audit composition and document consumer adoption
status: ready-for-agent
---

# Qualify audit composition and document consumer adoption

## Problem and Outcome

A repository dependency or active-transaction assertion does not prove that evidence joins the handler's actual
transaction/connection. Under [EPIC-00017](../epics/00017-EPIC.md), give integrators complete package coverage,
reusable conformance scenarios and truthful adoption guidance for intentional opt-in/opt-out. Show ordinary Role
composition and the conditions for retiring Agent OS's business-aware workaround, without claiming that package
fixtures qualify actual consumer persistence, sinks or deployment.

## Use Cases and Contracts

| Integrator/maintainer trigger | Commands / Queries / Events | Outcome and evidence |
| --- | --- | --- |
| Choose enabled or disabled audit per use-case owner | Existing constructors, Commands and synchronous calls | Explicit optional last repository injection, provenance responsibilities and no durability promise when omitted/null. |
| Implement storage and shared transaction composition | Evolved AuditEvidenceRepository and ordinary TransactionalUnitOfWork | Document complete typed mapping/schema obligations and actual connection/transaction participation requirements. |
| Replace Role interception with package evidence | Existing Role create/rename/remove handlers and post-commit facts | Demonstrate ordinary transactions plus upgraded audit adapter, with real qualification conditions before workaround retirement. |
| Review package completion | All 37 CommandHandlers plus related synchronous outcomes | Complete coverage/disposition and scenario evidence, not a sampled list or claim based solely on injection. |
| Diagnose a fault or plan consumer adoption | Existing failure/publication contracts; current guide examples | Distinguish rollback, uncertainty, committed publication failure, external-effect recovery and missing actual consumer evidence. |

No new audit Query/reporting API, permission grant, transport route or business Event is required. Existing Queries
remain reads; evidence is not automatically public response data, logging or an authorization grant.

## Requirements and Ownership

- Start an outcome/coverage matrix early, using the current complete inventory rather than waiting for final
  implementation. Account for 20 User/credential/session handlers (TICKET-00044), 11 Role/membership/Agent-admin
  handlers (TICKET-00045) and six Feature/policy handlers (TICKET-00046), plus TICKET-00047's synchronous Agent and
  TICKET-00044's synchronous User journeys. Refresh against source changes; conditional paths count explicitly.
- Record each outcome's owner, actor/provenance, enabled/disabled behavior, meaningful/no-change/rejected
  disposition, typed subjects, applicable revisions/relationships/correlation and transaction/publication/
  external-effect boundaries. Link owned acceptance/check evidence; injection counts alone do not prove coverage.
- Provide reusable package conformance scenarios/fixtures demonstrating actual shared transaction identity,
  mutation/evidence rollback on audit failure/concurrency loss, committed evidence despite publisher failure,
  actor validation before first required mutation, no-change/same-key resolution and batch changed-subject behavior.
  Assert durable modeled outcomes, not only invocation order. Distinguish these fixtures from real database proof.
- Document actual consumer adapter/schema mapping requirements: actor and subject kinds plus IDs, unique evidence
  identity, trusted occurrence time, complete applicable revision/incarnation data, typed relationships/scoped
  correlation and bounded safe context. Enabled adapters must reject unsupported evidence rather than discard it.
  Consumers own storage/access/retention and cannot claim that strings/regex alone sanitize free-text fields.
- Show ordinary Role repository/transactions/audit adapter composition sharing the actual connection. Include
  rename/remove revisions and removal reference fences, not just create. An active-transaction assertion alone
  cannot establish participation. No business-aware decorator or post-commit event subscriber is required.
- Document prerequisites to retiring Agent OS RoleMutationUnitOfWork, RoleMutationAudit and PostgresRoleMutationAudit:
  upgraded general audit mapping/schema, correct package injection/provenance and actual shared-connection failure/
  concurrency/publication qualification. Current adapters are not assumed compatible. No consumer edit, SQL/schema
  operation or deletion is authorized here; external qualification gaps remain explicit, not invented passes.
- Reconcile current project profile, operation/recovery guidance, examples and Unreleased notes with the approved
  optionality everywhere, including formerly mandatory writers. Explain mixed per-owner composition and later
  opt-in's lack of historical backfill; no durable guarantee applies to bypass repository writes or disabled use.
  Preserve historical release/acceptance evidence instead of rewriting it as the new contract.
- Follow ADR 0011: document breaking current constructors/messages/persisted shapes and consumer obligations,
  without legacy actor-string readers, mandatory-constructor facades, overload modes, migration/backfill machinery
  or a chosen release version. Keep restricted criteria/private evidence out of public records and examples.
- Preserve existing entry authorization and target/fence policy. Explain system/anonymous attribution without
  accounts, ambient principal lookup or privilege; protect audit access and avoid copying secret/raw payload/
  exception data into records, logs or public responses. Guaranteed rejected-attempt storage remains excluded.

## Dependencies and Sequencing

Inventory/conformance design and guidance foundations start early alongside [TICKET-00043](00043-TICKET.md).
Final package reconciliation consumes actual outputs/evidence from TICKET-00043 through TICKET-00047, using the
approved TASK edges below. This is not a blanket late testing gate or a reason to defer family checks, current
documentation or independent acceptance. No completion claim can skip an uncovered outcome.

EPIC-00015 retains architecture and eventual Agent Command conversion; EPIC-00016 retains typing. Assign overlaps
and cross-EPIC guidance amendments visibly without enlarging existing TASKs or duplicating correction acceptance.
No new Common API is demonstrated as necessary; record any actual affected qualification hold instead of creating
an all-upstream dependency. Actual consumer implementation/adoption is a separately owned obligation, not a hidden
requirement to change a sibling repository before this package guidance can be accepted.

## Acceptance Evidence

- [ ] A refreshed exhaustive matrix accounts for all 37 handlers and agreed synchronous outcomes, including enabled/
  disabled, anonymous/self-service/admin, batch, no-change, retained-key, failure/expiry and recovery paths.
- [ ] Reusable package scenarios prove shared transaction identity and modeled state/evidence outcomes, covering
  rollback/concurrency loss, uncertain commit where applicable, publisher failure and external-effect distinctions.
- [ ] Role create/rename/remove examples use ordinary shared transactions and the evolved audit contract, retain
  revisions/reference fences, and state concrete adapter/schema/qualification requirements before workaround removal.
- [ ] Current guidance and Unreleased notes explicitly amend former mandatory-audit guarantees, cover optional
  provenance/opt-in/opt-out and current breaking contracts, and preserve historical release/acceptance records.
- [ ] Secret/access/context-admission limits and actual consumer storage/connection/sink/recovery qualification gaps
  are explicit; no package fixture, planning count or guide is presented as real consumer proof or deployment authority.
- [ ] Family slices retain their own tests/docs/gates and independent acceptance. Executable conformance/tooling
  changes receive applicable focused/regression-first checks, canonical full gate/exact owned production statement
  coverage and independent Review/behavioral QA; documentation-only checks follow the local Testing standard.

## Exclusions

Production persistence adapters or database operations, actual consumer/sibling repair or workaround deletion,
new audit reporting/retention/transport systems, permission changes, outbox/replay/framework orchestration,
compatibility/migration/backfill machinery, release/version selection, signing, publication and deployment.

## Approved TASK Plan and Coverage

John approved the complete four-TASK plan, now written as kind chore with explicit early foundations:

| TASK | Order | Independently delivered outcome | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00166](../tasks/00166-TASK.md) | 399 | Complete living outcome inventory and conformance/guidance design foundations; no new scanner or gate. | None |
| [TASK-00167](../tasks/00167-TASK.md) | 400 | Reusable audit transaction conformance, reopened supported evidence mapping and wrong-transaction negative controls. | TASK-00122 |
| [TASK-00168](../tasks/00168-TASK.md) | 421 | Actual ordinary Role create/rename/remove composition, complete mapping guidance and concrete workaround-retirement prerequisites. | TASK-00167, TASK-00153 |
| [TASK-00169](../tasks/00169-TASK.md) | 422 | Complete integrated current-source/evidence reconciliation, missing cross-family proof and aligned current guidance/Unreleased notes. | TASK-00166/00167/00168; TASK-00123–00127; TASK-00153–00165 |

All four are ready-for-agent because scope is settled; executability still requires unfinished blockers. Orders
399/400 favor foundations before family completion without changing earlier scope/orders/blockers. Inventory is
independent; reusable executable conformance consumes the actual shared contract, not every family. Role composition
requires both its reusable suite and real implemented lifecycle audit. Final reconciliation has 21 explicit direct
output/evidence suppliers (three foundations/composition plus 18 family tasks); shared identity/evidence suppliers
TASK-00121/00122 are inherited. No earlier family acquires a new dependency on these foundations or final acceptance.

The baseline inventory accounts for all 37 handlers as 20 + 11 + 6, seven synchronous User operations and eight Agent
outcome entries across six Agent services. These 52 accounted entry points are not 52 writers, an exhaustive branch
count or a permanent source limit. Recovery delegates recording and nextRunAt stays pure scheduling. Refresh actual
added/removed/relocated/narrowed or already converted owners, with actor/provenance, optionality, evidence facts,
meaningful/no-change/rejected dispositions and transaction/publication/external-effect boundaries. Pending proof stays
pending; planning allocation/injection/terminal status alone establishes no source or runtime qualification.

TASK-00167 reuses actual typed evidence/ordinary transactions and test-only fixtures, proving persisted modeled
outcomes, reopening and misbound-transaction detection rather than active flags or collaborator order. It does not
implement every family's optionality or admission. TASK-00168 consumes actual Role handlers and retains authoritative
incarnation/revisions, expected intent/reference fences, no-change, rollback/concurrency/uncertainty and ordinary
publication failure. Mapping/schema/access/context and actual shared-connection qualification are concrete consumer
prerequisites, not authorization to edit Agent OS or remove RoleMutationUnitOfWork/RoleMutationAudit/
PostgresRoleMutationAudit. Package bindings are not real SQL/process/consumer proof.

TASK-00169 reconciles complete candidate-compatible family acceptance and adds only missing cross-family proof.
Preserve mixed opt-in/opt-out, no later backfill, committed terminal-replay revocation despite refusal, benign no-change,
changed-subject batches, retained-origin restart and claim-path expiry/exhaustion. External send/cleanup cannot be
rolled back by database audit failure; rewrap stays inside its bounded fenced transaction. Only issuance retains
confirmed-result sanitized publication warnings. Reconcile current profile/guides/examples/Unreleased notes without
rewriting historical acceptance or weakening actual authorization/recovery fences. Substantive supplier defects
return through their real owner and affected independent acceptance, not a duplicate redesign here.

Each executable slice retains its own focused checks, supported minimum/ordinary composition, canonical full gate/
exact coverage, complete evidence and independent Review/nonvisual behavioral QA. Strictly documentation-only work
uses the Testing standard's proven input-equivalence/receipt rule plus targeted checks; it cannot claim a fresh build.
Default product tests prove package behavior, not Markdown/planning or tooling self-qualification; bin/quality remains
the sole gate definition. Keep private criteria and detailed evidence private; no tracked check requires private
.runs files. Obtain complete applicable approved criteria before implementation/review without disclosure.

No blanket architecture/typing/Common, Agent Command-conversion or generic UoW dependency follows. Native UoW mixed
plus matching local @var for known owned callback results remains sufficient; no wrapper/result DTO or redundant
runtime check. Apply ADR 0011 without aliases/legacy readers/facades/modes/migration/backfill. Actual consumer storage/
locks/process/sink/key/restoration/activation/use qualification remains separate, not hidden sibling implementation
required for accepting truthful package guidance. Choose the execution checkout separately; existing Board priority
and task scope/orders/blockers remain unchanged.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 399 | [TASK-00166](../tasks/00166-TASK.md) | Maintain complete audit outcome coverage | in-progress |
| 400 | [TASK-00167](../tasks/00167-TASK.md) | Provide reusable audit transaction conformance | ready-for-agent |
| 421 | [TASK-00168](../tasks/00168-TASK.md) | Demonstrate ordinary Role audit composition | ready-for-agent |
| 422 | [TASK-00169](../tasks/00169-TASK.md) | Qualify integrated audit coverage and reconcile guidance | ready-for-agent |
## Decisions and Progress

John approved this requirement in EPIC-00017's six-TICKET split and subsequently the complete four-TASK plan, now
TASK-00166–00169. All six audit TICKETs are fully TASK-planned as 24 chores (2 + 5 + 5 + 4 + 4 + 4), not implemented/
qualified; unfinished children keep their parents open. Readiness means settled scope, not supplier completion,
implemented conformance, consumer proof or independent acceptance.

Planning only: no source/dependency/Common/consumer mutation, product build, new checkout/branch/worktree,
commit/publication, workaround removal, schema/data operation, release or deployment follows.

Planning generation/read-only validation passes (238 records, 112 active), alongside documentation links and diff/
new-record whitespace checks. Metadata confirms orders 399/400/421/422, exact blocker counts 0/1/2/21 and all six audit
plans as 24 chores. Generated children/frontier/waiting and current next handoffs agree; first Board selection remains
TASK-00107. These are planning structure/coverage checks, not source/runtime/supplier/consumer qualification; product
source/dependency status is unchanged and no product build was run.

TICKET-00049's subsequent two-TASK declaration plan is now approved/written as TASK-00170/TASK-00171, not implemented/
qualified or a new audit dependency. TICKET-00050's subsequent Feature plan is also written as TASK-00172–00175,
not implemented/native qualification. TICKET-00051's subsequent portable Permission plan is now written as
TASK-00176–00179, not implemented/qualified. TICKET-00052's reduced two-chore plan is now written, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00016's accepted
six-TICKET split is TASK-planned, not implemented/qualified; EPIC-00015's added Agent conversion and actual Common
supplier planning/qualification remain separate unfinished handoffs. No earlier Wayfinder phase applies to this
standalone audit destination. Finish selected remaining planning before Board-based execution selection; TASK-00107
remains first and implementation checkout choice remains explicit.
