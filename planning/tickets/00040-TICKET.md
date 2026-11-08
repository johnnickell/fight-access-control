---
id: TICKET-00040
epic: EPIC-00016
title: Make command and synchronous Application contracts explicit
status: ready-for-agent
---

# Make command and synchronous Application contracts explicit

## Problem and Outcome

Application contracts span far more than CommandHandler methods: synchronous authentication, credential workers,
authorization, subscribers and collaborators also exchange values and classify failures. Under
[EPIC-00016](../epics/00016-EPIC.md), audit and correct every command and all remaining non-presentation Application
code so native/static types, actual outcomes and intentional failures agree without changing business guarantees.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Authorized caller administers identities, Roles, Permissions, Features or policy | Every existing Command and associated success/failure facts | Precise payload/collaborator contracts, intentional refusal and current atomic mutation/audit behavior. |
| User authenticates or changes credentials | Existing synchronous secret-bearing calls; current redacted facts | Exact safe result or intentional failure, with secrets non-serializable and effects correctly ordered. |
| Authorized worker issues, delivers, recovers or maintains credential operations | Existing worker/service inputs and current Events | Exact state/claim/result types preserve uncertainty, recovery and committed-result warning semantics. |
| Application resolves authority or reacts to a fact | Existing authorization ports, specifications and EventSubscribers | Typed contracts and validation preserve actual permission decisions, freshness, lifetime and rejection behavior. |
| Provider, transaction or notification fails | Existing failure evidence only | Primary fault, rollback/uncertainty/confirmed-commit distinction and safe redaction remain authoritative. |

## Requirements and Ownership

- Account for all 37 current CommandHandlers and every non-query/non-presentation Application declaration in the
  refreshed inventory: services, service ports, coordinators, subscribers, specifications, clocks, security and
  authority providers, continuations, delivery/maintenance/recovery, shared declarations and safe result/warning
  types. Audit code wherever it lives today, following approved target ownership without excluding legacy locations.
- Correct parameters/properties/results, arrays/collections, callable arguments/results, optional states and
  collaborator interfaces against actual behavior. Refine genuinely known types; justify dynamic external
  boundaries and validate them at their owner rather than falsely narrowing them with an unchecked annotation.
- John explicitly accepts native commitTransactional() remaining mixed. For a local result returned by the owned
  callback, a correct local @var (including a known tuple/union where applicable) is sufficient. No Common UoW
  generic API, qualification task, replacement wrapper/result DTO or redundant result check is a prerequisite.
  Inspect all callback branches so the annotation records a known fact, not a cast over untrusted external data.
  This does not establish bus/Event payload inference or relax validation at genuinely dynamic external boundaries.
- Classify intentional Application outcomes using actual qualified Common categories: missing resource,
  authentication/permission refusal, state conflict, explicit failed caller precondition, unusable input, business
  rule, gone resource, rate limit and temporary unavailability where applicable. Preserve structured validation.
- Keep Domain decisions/exceptions in Domain; translate only known outcomes into Application intent. Never derive
  category from message/code text or blanket-wrap storage faults, unknown throwables, incorrect identities or
  programmer errors as expected rejection. Internal contract violations remain safe server faults. Reconcile the
  local Architecture standard's older LookupException recommendation when adopting the new Application vocabulary;
  a generic lookup exception is not automatically a Common safe HTTP not-found category.
- Audit absence per operation. Keep successful void commands void, preserve legitimate idempotent/no-op and
  anti-enumeration behavior, and do not manufacture not-found failures where the use case intentionally succeeds.
- Preserve authorization and target/reference/cohort/restoration fences, exact expected-state/CAS, audit atomicity,
  aggregate ownership, transaction duration, primary-failure preservation and commit-before-success publication.
  Preserve uncertain commits and the approved Agent issuance committed-result/sanitized-warning exception; no
  post-commit failure may falsely imply rollback or duplicate issuance.
- Raw secret-bearing operations remain synchronous with sensitive parameters and non-serializable results.
  Existing serializable Commands and facts use their configured buses/dispatcher; no direct handler bypass,
  secret-bearing message or blanket serialization of internal results is introduced.
- Presentation/catalog bindings belong to TICKET-00041 regardless of current namespace; query behavior belongs
  to TICKET-00039. This ticket owns all other Application declarations and behavior, including ports. TICKET-00038
  owns Query/Command message generics; this ticket owns remaining Event-subscriber relationship adoption. Shared
  Domain types belong to TICKET-00037. Assign shared code edits once at TASK planning.

## Dependencies and Existing Work

Coordinate with [TICKET-00035](00035-TICKET.md) for User transition/synchronous-capability redesign,
[TICKET-00036](00036-TICKET.md) for Agent operation/read ownership and TICKET-00034/TASK-00110 for canonical imports.
Do not duplicate those requirements or require every pending architecture refactor before an independent typing
correction. Reconcile actual current code and accepted target; record real TASK supplier edges later.

Affected Common failure adoption requires implemented/independently accepted/qualified tagged APIs and a sufficient
minimum/ordinary composition lane. An unavailable supplier holds only affected work. For changes touching existing
restricted operation/recovery requirements, obtain their authorized private brief before implementation/review;
public planning neither replaces nor discloses it. No consumer/provider implementation is authorized here.

## Acceptance Evidence

- [ ] Every CommandHandler and every remaining non-presentation Application declaration has an inventory owner,
  exact contract and correction or justified unchanged disposition, including synchronous and event-driven paths.
- [ ] Valid operation, invalid input, intentional absence/no-op, business refusal, stale/precondition conflict and
  internal/provider failure each retain the correct typed outcome; unknown faults are not relabeled by text.
- [ ] Native/static callable, collection, port and result types match actual collaborators; unsafe assertions,
  casts or suppressions do not conceal discrepancies. Success-only void commands remain void.
- [ ] Regression scenarios preserve authority/fences, redaction, atomic persistence/audit, notification ordering,
  uncertain/confirmed commits, recovery and committed-operation warnings without secret-bearing messages.
- [ ] Domain-to-Application dependencies are absent; relevant Common categories/minima have actual qualification,
  and unqualified supplier/consumer evidence remains an explicit limitation.
- [ ] Each slice updates current callers/examples and has focused behavior/static checks, full canonical gate,
  exact owned statement coverage, complete local evidence and independent Review/applicable QA.

## Exclusions

New business policy/grants, the separate EPIC-00015 service redesign, consumer cryptography/persistence/runtime,
Common implementation, compatibility/migration, secret-bearing CQRS and release/deployment. Query and transport
requirements are assigned to their own tickets, not omitted from the parent EPIC.

## Approved TASK Plan and Coverage

John approved the revised complete ten-TASK plan after explicitly removing the proposed UoW generic supplier hold.
All records are kind chore, orders 315–324, with empty local blocked_by; preference order is not a serial chain.

| TASK | Order | Primary Application files / CommandHandlers | Delivered contract area | Status |
| --- | --- | --- | --- | --- |
| [TASK-00136](../tasks/00136-TASK.md) | 315 | 4 / 0 | Three Event-to-delivery subscribers and shared Clock; qualified Event typing only. | needs-info |
| [TASK-00137](../tasks/00137-TASK.md) | 316 | 10 / 8 | User creation, invitation/recovery/correction and lifecycle. | done |
| [TASK-00138](../tasks/00138-TASK.md) | 317 | 24 / 11 | Human credential requests/workers and shared provider/cipher/invocation/results. | ready-for-agent |
| [TASK-00139](../tasks/00139-TASK.md) | 318 | 11 / 1 | Seven synchronous User security operations, RevokeSession and helpers/results. | ready-for-agent |
| [TASK-00140](../tasks/00140-TASK.md) | 319 | 7 / 7 | Role lifecycle/Permission membership and User Role membership. | ready-for-agent |
| [TASK-00141](../tasks/00141-TASK.md) | 320 | 5 / 4 | Agent profile/direct-Permission administration and its non-handler coordinator. | ready-for-agent |
| [TASK-00142](../tasks/00142-TASK.md) | 321 | 12 / 6 | Feature/policy mutation, availability/discovery and remaining apply-side planner audit. | ready-for-agent |
| [TASK-00143](../tasks/00143-TASK.md) | 322 | 9 / 0 | Existing Agent provision/rotation, safe results/warning and issuance ports. | ready-for-agent |
| [TASK-00144](../tasks/00144-TASK.md) | 323 | 14 / 0 | Agent delivery, revocation, recovery, maintenance and worker ports/results. | ready-for-agent |
| [TASK-00145](../tasks/00145-TASK.md) | 324 | 23 / 0 | User/Agent authority, inward admission, exact Permissions and continuation. | ready-for-agent |

Primary path manifests account for 119/119 remaining Application files and 37/37 CommandHandlers without overlap/
omission: 4 + 10 + 24 + 11 + 7 + 5 + 12 + 9 + 14 + 23 files, and 8 + 11 + 1 + 7 + 4 + 6 handlers.
Together with 22 QueryHandler files (TICKET-00039) and 13 presentation files (TICKET-00041), all 154 current Application
files have a planning owner. This is source-path allocation, not completed source review or executed type/runtime/
supplier/consumer proof. Refresh actual candidates and account for additions/moves/removals and boundary-level ownership.

The 13 presentation files currently under Application comprise Agent's five root binding/catalog declarations,
RequiresAgentPermission Attribute, three protocol availability classes, three Tool declarations and FeatureFlag.
Transport-neutral AdmissionMode/PermissionDeclaration and actual authority providers remain TASK-00145-owned.
No namespace move or protocol placement is implemented by this allocation.

### Local transaction results are settled, not an information hold

John's approved example is a local @var describing the success Event returned by a handler-owned callback passed to
commitTransactional(). Native mixed intentionally remains. Apply that decision to known local results in the owned
use cases: correct annotations are sufficient, with no generic UoW supplier, replacement API, extra defensive check
or uniform result DTO. Do not reopen the removed hold under callback/enforcement wording. The annotation must agree
with every actual return branch; it neither validates unrelated external data nor proves bus/Event inference.

TASK-00136 retains ONLY the separate Event envelope/payload/registration qualification hold. Installed Common v1.4.0
is unparameterized at those relationships; actual implemented/independently accepted/qualified tagged APIs and
sufficient-minimum/ordinary composition evidence are needed. No supplier ID is invented, no upstream plan is claimed
scheduled, and related Common transaction implementation work is not proof of Event typing or a UoW prerequisite.
This Event hold does not block the nine independent operation/authority slices. Other slices still verify supported
Common failure/port APIs and actual composition; accepted planning is not supplier qualification.

### Shared edits and complete-path acceptance

- TASK-00138 owns shared human provider/results and PasswordResetRevocationService, TASK-00139 session helpers,
  TASK-00143 AgentOperationAuthorization and TASK-00145 ExactPermissionResolver. Include directly necessary callers/
  tests/docs once, not duplicate helper implementations or force all dependent semantic work into a prefactor chain.
- TASK-00135 retains Query preparation/preview semantics and its necessary shared planner/discovery/apply-caller
  corrections. TASK-00142 owns the remaining non-query audit, consuming actual dispositions without duplicate acceptance.
- TASK-00144 reconciles recovery's existing direct QueryHandler injection with configured Query dispatch, assigning
  caller/adoption edits once with TASK-00128 and read semantics with TASK-00134. No direct-handler bypass, shadow bus
  or local assertions masquerading as generic inference. Its worker behavior does not claim TASK-00128's proof passed.
- TASK-00128/00129 retain Query/Command generic adoption. TICKET-00037 retains shared Domain typing; EPIC-00015
  retains capability/transition/operation redesign and real Agent Command conversion; TASK-00130 retains conversion
  dispatch qualification. Current issuance/service correction does not wait for that future conversion.
- EPIC-00017 retains optional audit feature ownership; preserve actual adopted audit atomicity here without duplicate
  implementation. TICKET-00041 retains broader presentation and TICKET-00042 exhaustive enforcement/final reconciliation.

Each slice retains exact own contracts, regressions for demonstrated defects, direct caller/examples alignment,
relevant actual routing/safe-error composition, supported tagged minimum/ordinary lanes, canonical full gate/exact
owned coverage, complete local evidence and independent Review/nonvisual behavioral QA. Preserve deliberate provider/
neutral pre-admission containment and secret redaction without fabricating ordinary rejection from unknown faults.
No source overlap alone establishes a dependency, and no blanket wait on Common/Domain/all architecture work follows.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 315 | [TASK-00136](../tasks/00136-TASK.md) | Preserve Event-driven delivery contracts | needs-info |
| 316 | [TASK-00137](../tasks/00137-TASK.md) | Correct User account lifecycle contracts | done |
| 317 | [TASK-00138](../tasks/00138-TASK.md) | Correct human credential request and worker contracts | done |
| 318 | [TASK-00139](../tasks/00139-TASK.md) | Correct synchronous User and session contracts | done |
| 319 | [TASK-00140](../tasks/00140-TASK.md) | Correct Role and User membership contracts | done |
| 320 | [TASK-00141](../tasks/00141-TASK.md) | Correct Agent administration contracts | done |
| 321 | [TASK-00142](../tasks/00142-TASK.md) | Correct Feature and policy mutation contracts | ready-for-agent |
| 322 | [TASK-00143](../tasks/00143-TASK.md) | Correct Agent issuance and result contracts | in-progress |
| 323 | [TASK-00144](../tasks/00144-TASK.md) | Correct Agent delivery, recovery and maintenance contracts | ready-for-agent |
| 324 | [TASK-00145](../tasks/00145-TASK.md) | Correct authority and continuation contracts | ready-for-agent |
## Decisions and Progress

TASK-00139's synchronous User/session slice has independent Review v3 ACCEPT and nonvisual QA PASS at clean
candidate `07a50e549a84b45b74e4d72cd6f0a44eb6aa8439` against `80f6d0d213a2324fdc02f29a26a17fc82ccd3702`, with
qualified complete-gate/exact-coverage proof. The owning TASK records acceptance and limits; its canonical Land
handoff owns current delivery/resource results. TASK-00139 is done, but unfinished children keep this TICKET and
EPIC-00016 open. This is not acceptance of other Application contracts, optional audit or consumer composition.

TASK-00138 is independently accepted and done for its 24-declaration / 11-handler human credential request/worker
slice. Review v3 ACCEPT and nonvisual QA PASS cover clean candidate
`e122bd09eac2a17e261257f4e2c34f486815e62b` against `80f6d0d213a2324fdc02f29a26a17fc82ccd3702`, with no findings.
The owning TASK retains verification and warnings; canonical `.runs/handoffs/TASK-00138/land.md` owns subsequent
publication/administrative bridge/resource outcomes. Unfinished children keep this TICKET and EPIC-00016 open;
no sibling, whole-library, generic bus or consumer qualification is implied. The approved-plan statuses above
remain historical planning evidence; the generated child table reflects current record status.

### Historical planning checkpoints

John approved this requirement in the six-TICKET split, then the revised complete ten-TASK plan, now written as
TASK-00136–00145. At that planning checkpoint nine were ready-for-agent; current status belongs to the owning
TASKs and generated child table. TASK-00136 remains needs-info for Event typing only. Native UoW mixed
and known local callback-result @var are explicitly accepted with no UoW supplier prerequisite. No local/mutual
blockers are demonstrated and existing TASK scope/order/blockers and Board priority remain unchanged.

That approval checkpoint was planning only: no implementation, source/dependency/sibling/consumer mutation,
product build, new checkout/branch/worktree, commit/publication, release or independent acceptance was claimed. Private criteria must be obtained
through the authorized channel before applicable implementation/review, not inferred available from public planning.

At this ten-TASK checkpoint, planning generation/read-only validation passed (209 records, 83 active), alongside
documentation links and diff/record whitespace checks. Manifest resolution allocates 119/119 remaining Application
files and 37/37 CommandHandlers
without overlap/omission; 119 + 22 QueryHandler + 13 presentation files reconcile to all 154 Application files.
This is planning coverage only, not completed source review or generic/runtime/supplier/consumer qualification;
no product build was run and the Board still selects TASK-00107 first.

TICKET-00041's subsequent four-TASK plan is now approved/written as TASK-00146–00149, with real targeted suppliers
for profile Tool/schema agreement and no blanket architecture/Common or UoW hold. Earlier necessary caller/schema
fixes and all existing scope/blockers remain owned as above.

TICKET-00042's three-TASK plan is now approved/written as TASK-00150–00152. Its early foundations remain independent;
final enforcement consumes actual accepted contracts, retaining the Event-only hold and settled local UoW annotations.

TICKET-00045's separate administration audit plan is now approved/written as TASK-00153–00157.
TICKET-00046's separate four-TASK Feature/policy audit plan is now approved/written as TASK-00158–00161.
TICKET-00047's four-TASK audit plan is now approved/written as TASK-00162–00165.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00015's conversion
decomposition and separate Common supplier planning/qualification remain unfinished handoffs. Implementation checkout choice is
separate and the Board remains execution authority with TASK-00107 first.
