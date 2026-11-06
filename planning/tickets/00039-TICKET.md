---
id: TICKET-00039
epic: EPIC-00016
title: Guarantee exact query results and explicit absence
status: ready-for-agent
---

# Guarantee exact query results and explicit absence

## Problem and Outcome

Nullable lookup results and unchecked repository metadata push use-case contract decisions into callers. Under
[EPIC-00016](../epics/00016-EPIC.md), every query must return its promised typed result or an intentional failure,
with exact identity/correlation and request/result consistency enforced by the owner.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Authorized caller retrieves a required resource | Existing Get-by-ID/profile queries where resource is required | Exact non-null safe View or intentional Common Application not-found failure. |
| Authorized caller discovers optional status/work | Existing invitation/credential/Agent-operation status and discovery Queries | Explicit typed absence/state or documented required-result failure; meaningful indeterminate status survives. |
| Caller requests a list/page, plan or count | All existing list, due/expired/maintenance, validation, preview and count Queries | Exact declared collection/result type and request-consistent bounds, scope and metadata. |
| Repository returns inconsistent identity/data or fails | Same Queries; no Command or Event | Internal/operational failure propagates safely, never unrelated data or fabricated not-found/empty success. |

## Requirements and Ownership

- Audit and correct all 22 current QueryHandlers and their actual candidate additions/moves. Include every result
  family, not only User/Role/Permission/Agent lookups: Feature preparation/availability-related reads, policy
  preview, active sessions, invitation/credential status and Agent operation/scheduler/maintenance/count paths.
- Required resource queries return the expected non-null View/result or an intentional qualified Common
  Application failure, normally ResourceNotFound for deliberately missing-resource intent. Repositories retain
  nullable lookups. Inspect existing callers/catches/tests/examples and remove obsolete nullable handling directly.
- Explicitly model meaningful optional discovery/status absence in typed results/states. Preserve indeterminate
  Agent-operation outcomes, anti-enumeration, concealment and current authorization/disclosure ordering. No blanket
  rule turns all null repository results into 404 or strips current unknown/indeterminate information.
- Validate exact requested identity/correlation before returning a resource. A wrong ID, generation, key, purpose,
  destination or scope is an internal contract fault where that relation is promised, not an absent resource.
  Do not repeat native return-type checks simply to relocate defensive caller code.
- Verify every list's element/result type and applicable request/result metadata, page-size bounds and promised
  filter/scope. Reject inconsistent or excessive results rather than silently truncate, relabel or return unrelated
  data. State valid empty/out-of-range semantics against the actual repository snapshot contract; do not infer
  cross-read atomicity from totals or invent a more powerful persistence promise.
- Common owns universal ResultSet/collection invariants; consume qualified upstream guarantees and retain only
  AccessControl request-specific checks here. Missing upstream work is an explicit affected-adoption prerequisite,
  not a shadow ResultSet. Scalar/count and array/list queries receive equally precise guarantees.
- Preserve query read-only behavior: no aggregate mutation, business commit, audit mutation or Event dispatch.
  Permissions and fresh authoritative snapshots remain required; typed results do not authorize access.
- Own query/result semantic changes and their local callers here. TICKET-00037 owns shared Domain definitions,
  TICKET-00038 owns generic bus adoption, and TICKET-00041 owns exported presentation/schema alignment. Assign
  shared edits once and include directly affected call sites/tests in each complete implementation path.

## Dependencies and Sequencing

Use shared Domain/repository and dispatch contracts as they become available; TASK decomposition records concrete
supplier edges, not a blanket wait for all refactors. Verify actual qualified/tagged Common Application failures
and result APIs/minimum before affected adoption. Keep Domain independent of Application when translating known
outcomes. Coordinate operation/read ownership with TICKET-00036 without duplicating that redesign or certifying
unimplemented narrow interfaces. External consumer nullable-result obligations are documented, not repaired here.

## Acceptance Evidence

- [ ] Every QueryHandler/result family has an inventory disposition, exact input/result/failure contract and
  affected caller evidence; no query is omitted because its result is a scalar, array, plan or optional state.
- [ ] Required lookups prove success and intentional absence; optional status proves its explicit states. Existing
  authorization ordering, concealment, redaction and indeterminate-operation behavior remain intact.
- [ ] Adversarial repository outputs prove exact identity/correlation, metadata and page-bound rejection where
  promised; invalid output/operational failure cannot become an empty result or misleading ResourceNotFound.
- [ ] Typed records and valid empty/out-of-range pages work under the actual persistence contract; no new hidden
  snapshot/transaction guarantee or duplicate native instanceof checks are introduced.
- [ ] Queries remain read-only and current owned nullable callers/tests/examples are updated directly, with
  external breakage obligations explicit and no legacy nullable mode.
- [ ] Real configured dispatch and relevant safe error composition work with qualified minimum/ordinary Common
  lanes; each slice has focused regressions, canonical full gate/exact coverage, local evidence and Review/QA.

## Exclusions

Command mutation semantics, new query features, consumer persistence/Actions, grants, guessed supplier releases,
compatibility paths and new Query side effects. HTTP responses remain presentation-owned; this ticket throws
transport-neutral intent rather than constructing status codes or public diagnostics.

## Approved TASK Plan and Coverage

John approved the complete five-TASK split, now written with kind chore and empty local blocked_by fields:

| TASK | Order | Owned outcome / baseline handler coverage | Status / actual hold |
| --- | --- | --- | --- |
| [TASK-00131](../tasks/00131-TASK.md) | 310 | Six required-resource queries: exact non-null safe Views or intentional Application failure. | ready-for-agent; no demonstrated local blocker or newly missing Common API. |
| [TASK-00132](../tasks/00132-TASK.md) | 311 | Six paged queries: qualified Common container invariants plus AccessControl request/element/scope consistency. | needs-info for separately planned/implemented/accepted/tagged Common ResultSet validation and minimum evidence. |
| [TASK-00133](../tasks/00133-TASK.md) | 312 | Four human credential status/due/expired queries: explicit absence/state and exact bounded work. | done; independent Review ACCEPT and nonvisual QA PASS for the owning TASK's accepted candidate. |
| [TASK-00134](../tasks/00134-TASK.md) | 313 | Four Agent operation/scheduler/maintenance/count queries: exact authorized safe outcomes. | ready-for-agent; applicable private criteria must be obtained before implementation/review. |
| [TASK-00135](../tasks/00135-TASK.md) | 314 | Two preparation/policy-preview queries: truthful typed diagnostics/plans. | ready-for-agent; no demonstrated local blocker or newly missing Common API. |

The manifests account for 6 + 6 + 4 + 4 + 2 = all 22 current QueryHandlers without overlap/omission. This is planning
coverage, not an exhaustive source audit or executed runtime/type/supplier/consumer qualification. Refresh actual
candidates and follow added/moved/removed owners. No mutual blockers are demonstrated and priority is not a serial
chain. Orders 310–314 follow existing typing work without renumbering it or earlier tasks.

TASK-00132's hold is a concrete Common planning gap, not a claim that an upstream task is already scheduled. The
inspected Common ResultSet still lacks shared returned-page metadata admission. Common's completed TASK-00128
adds Pagination::strict() for request bounds and explicitly excludes ResultSet redesign; do not confuse it with this
supplier or AccessControl's same-numbered Query-dispatch task. Plan bounded Common result validation under Common's
1.x compatibility policy; establish actual accepted/tagged APIs and minimum/ordinary composition before adoption.
No Common ID is invented or placed in local blocked_by, and no Common file is written by this planning approval.

The ready slices still verify actual supported tagged failure/result APIs and minimum/ordinary lanes at execution;
readiness is not supplier/consumer acceptance. No blanket dependency on TASK-00128's generic bus adoption,
TASK-00110's canonical migration, all Domain corrections or every architecture refactor is introduced. Semantic
changes update directly affected declarations/callers/fixtures together; precise generic inference remains with
TICKET-00038. TICKET-00040/00041 retain other Application/presentation ownership and TICKET-00042 final reconciliation.

Preserve legitimate cross-family merging in TASK-00133: reject bad individual source batches before sorting/global
top-N selection, not every combined batch larger than the final limit. TASK-00134 distinguishes deliberate caller/
binding denial from a repository violating its promised key/filter contract and preserves indeterminate history.
TASK-00135 coordinates necessary shared planner corrections with apply callers once, without a second planner or
preview reservation. All slices retain read-only behavior, current docs/examples, complete focused proof/full gate/
exact coverage, local evidence and independent Review/behavioral QA. Choose the execution checkout separately.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 310 | [TASK-00131](../tasks/00131-TASK.md) | Make required resource queries exact and non-null | ready-for-agent |
| 311 | [TASK-00132](../tasks/00132-TASK.md) | Enforce paged query result contracts | needs-info |
| 312 | [TASK-00133](../tasks/00133-TASK.md) | Make human credential status and discovery results explicit | done |
| 313 | [TASK-00134](../tasks/00134-TASK.md) | Correct Agent operation, scheduler and count query contracts | ready-for-agent |
| 314 | [TASK-00135](../tasks/00135-TASK.md) | Make preparation and policy-preview results truthful | ready-for-agent |
## Decisions and Progress

TASK-00133's four human credential status/discovery paths are independently accepted and done, with explicit
absence, exact status correlation, per-source admission and aligned callers/schemas. The owning TASK records
candidate `c62d261726082547757cba98eb3af150afa4665d`, Review ACCEPT, nonvisual QA PASS and qualified complete-gate
proof; canonical `.runs/handoffs/TASK-00133/land.md` owns current delivery/resource results. Unfinished siblings
keep this TICKET open; no other Query, supplier or consumer boundary is qualified.

John approved this requirement within the six-TICKET split, then the complete five-TASK plan now written as
TASK-00131–00135. At that planning checkpoint four were ready-for-agent and TASK-00132 was needs-info for the
explicit Common ResultSet supplier and actual qualification; none was then implemented/accepted. Current child
status derives from the records below, including TASK-00133's subsequent acceptance.

No source/dependency/consumer or sibling change, product build, new branch/worktree, commit/publication, release
or independent acceptance follows. Earlier TASK scope/order/blockers and Board priority remain unchanged. The
requested short Common planning prompt is a handoff for a separate session, not creation of its missing supplier.

At this five-TASK checkpoint, planning generation/read-only validation passed (199 records, 73 active), plus documentation links and diff/record
whitespace checks. A refreshed source-name allocation accounts for 22/22 current QueryHandlers without overlap/
omission across the five tasks. This is planning coverage, not executed generic/runtime/supplier/consumer proof;
no product build was run. The Board still selects TASK-00107 first.

TICKET-00040's revised ten-TASK plan is now approved/written as TASK-00136–00145, with only Event typing held in
TASK-00136 and no UoW generic prerequisite. Shared Query/planner/caller ownership remains coordinated once.

TICKET-00041's four-TASK plan is now approved/written as TASK-00146–00149. Targeted profile/status edges consume
TASK-00131/00133; unchanged schema wire tests do not qualify or inherit TASK-00132's ResultSet runtime hold.

TICKET-00042's three-TASK plan is now approved/written as TASK-00150–00152. Independent foundations start early;
final reconciliation consumes actual accepted query contracts and retains the ResultSet qualification hold.

TICKET-00045's separate administration audit plan is now approved/written as TASK-00153–00157.
TICKET-00046's separate four-TASK Feature/policy audit plan is now approved/written as TASK-00158–00161.
TICKET-00047's four-TASK audit plan is now approved/written as TASK-00162–00165.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00015's conversion and actual Common supplier
planning/qualification remain separate unfinished handoffs, including the Common ResultSet plan. No implementation starts here.
