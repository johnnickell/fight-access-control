---
id: TICKET-00038
epic: EPIC-00016
title: Preserve message and result types through dispatch
status: ready-for-agent
---

# Preserve message and result types through dispatch

## Query amendment — 2026-11-09

John explicitly clarified and reinvoked Build TASK-00128: QueryBus fetch/dispatch remain mixed; each handler
serves one registered Query and declares its specific native/PHPStan result or throws. Known caller annotations,
including precise ResultSet elements, are permitted. They do not enforce runtime generics; genuinely dynamic
boundaries still require validation. For TASK-00128 this supersedes the original Query inference/no-assertion and
external generic-supplier requirements below. Qualify actual supported tagged APIs, every binding/caller and exact
static/runtime contracts, not an unavailable generic bus relationship. See the [current manifest](../../docs/query-dispatch-contracts.md).
Command/Event and TASK-00130's distinct Agent qualification remain separately owned; historical planning statements
below retain their original scope/date rather than imposing a current generic hold on TASK-00128.

## Problem and Outcome

A concrete handler return declaration does not give a QueryBus caller its result type when fetch/dispatch expose
unqualified mixed. Under [EPIC-00016](../epics/00016-EPIC.md), preserve the relationship between every owned message,
its registered handler and its result through actual configured buses. Adoption uses qualified Common contracts;
local casts/assertions are not proof of end-to-end typing. John's later TASK-00129 amendment selects a different
Command payload contract: one supported declaration and flat owned extraction annotation, with runtime routing
binding through a trusted matching registration map rather than a generic supplier prerequisite. That scoped amendment governs the Command clauses below;
Query-result inference and sibling acceptance remain unchanged.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Caller fetches a Query | All owned Queries, including lists/status/counts | Static inference produces the exact declared result, collection element or explicit optional state. |
| Caller dispatches a QueryMessage | Same Queries with message metadata | Payload/result relationship survives message creation, metadata operations and dispatch. |
| Caller dispatches a Command | All existing serializable Commands | Handler registration/payload contracts agree; successful void handling remains void. |
| Authorized Agent caller invokes issuance and reads its outcome | Void provisioning/rotation Commands and outcome Queries delivered by EPIC-00015 | Command payload/metadata/void contracts and exact Query-result inference survive sync/async-neutral composition; this ticket qualifies typing, not conversion. |
| Maintainer changes handler registration or caller expectations | Existing messages; no new Event | Incompatible use is rejected by static checks and applicable runtime dispatch rules, not silently asserted away. |

## Requirements and Ownership

- Inventory actual Common APIs consumed along every owned Command/Query path: payload type, registration class,
  handler input, native/static return, message construction/metadata transformations, configured bus and caller.
  Reconcile all 37 current command and 22 query handlers against a refreshed candidate, not a fixed sample.
- Common owns Query/QueryMessage/QueryHandler/QueryBus generic relationships and Command routing contracts.
  Existing Commands use the single registration/local annotation rule in the PHP standard, not an upstream generic
  prerequisite. Specify missing supplier guarantees precisely, then adopt actual supported APIs without a
  copied bus, shadow message hierarchy, AccessControl compatibility shim or direct-handler bypass.
- Preserve result inference through both fetch and dispatch. Account for scalar results, arrays/lists, typed
  ResultSets, safe Views, explicit optional states and the void Command contract. Registration must identify the
  payload type the handler actually accepts. For Commands the approved flat local annotation records that type;
  it is not static assignability proof. Trusted maps must match declarations; a focused expected-registration
  unit assertion suffices. Correct routing is qualified separately, not rejection of deliberately inconsistent maps.
- Prove expected inference and rejected incompatible caller/payload/handler use with executable static-analysis
  fixtures. Prove runtime registration, real bus routing and applicable result/failure propagation separately;
  PHPStan generics are not a native runtime guarantee.
- Use the result/absence contracts delivered by [TICKET-00039](00039-TICKET.md) and Application contracts from
  [TICKET-00040](00040-TICKET.md). Validate coverage of every handler here, but assign semantic changes to those
  owners rather than duplicate their acceptance. Shared Domain shapes remain with TICKET-00037.
- Qualify the amended Agent follow-up through TASK-00130, without implementing EPIC-00015's conversion. John chose
  void Commands and independent outcome Queries, invocable sync or async with no transport assumption. Qualify payload/
  metadata/void contracts and exact Query-result inference; no result-capable Common bus is needed. Actual missing
  generic APIs and the concrete conversion supplier hold only their affected qualification, not ordinary functional conversion.
- Preserve message identity, serialization, metadata, authorization-before-work, existing facts and transactional/
  post-commit behavior. No new grant, Event, commit or result DTO is required merely to obtain generic typing.
  Raw secrets remain synchronous/non-serializable and do not become messages.

## Dependencies and External Evidence

[TICKET-00034](00034-TICKET.md)/[TASK-00110](../tasks/00110-TASK.md) own canonical adoption already required by the
existing package. Coordinate overlapping imports/dependency minima once; this requirement owns the new end-to-end
typing guarantees, static fixtures and real dispatch proof, not a duplicate canonical migration.

Before adoption, verify actual implemented/independently accepted/qualified tagged supplier APIs and a sufficient
minimum using real AccessControl composition at that minimum and the ordinary dependency lane. Planning inspection
of Common's mixed bus interfaces is not evidence that generic replacements have shipped. No release number is
guessed and no external TASK is placed in local blocked_by metadata. Missing supplier evidence holds affected
adoption; audit, local contract correction and independent work can proceed. TASK planning must record actual
needs-info prerequisites rather than infer executability from this accepted TICKET's status.

## Acceptance Evidence

- [ ] A complete mapping covers every owned message, actual registered handler, payload, result and caller through
  real configured buses, including message metadata transformations and both Query invocation methods.
- [ ] Positive fixtures infer the exact Query result/element types without caller casts or unchecked assertions.
  Command fixtures prove actual native/static contracts and the approved local annotated extraction, not generic
  registration assignability. Negative fixtures check actual incompatible-use diagnostics without suppression.
- [ ] Runtime tests demonstrate actual routing, applicable registration rejection and failure propagation; generic
  static guarantees are explicitly distinguished from native PHP checks.
- [ ] Actual tagged/accepted supplier APIs and minimum/ordinary-lane evidence support newly consumed guarantees;
  unavailable proof is explicit and no Common implementation or development-snapshot adoption is substituted.
- [ ] Message metadata, identities, secret restrictions, existing effects and configured dispatch remain intact;
  commands without results remain void and no handler bypass or compatibility bridge is added.
- [ ] Each implemented slice has current examples, focused tests, canonical full gate/exact coverage, complete
  local evidence and independent Review/applicable QA. Package proof does not qualify a consumer deployment.

## Exclusions

Implementing Common generics, copying buses, mandatory Command result DTOs, new business messages, consumer Action
refactoring, changing serialization solely for static generics and duplicating TASK-00110. HTTP/protocol mapping
belongs to TICKET-00041; this ticket does not present failures or grant authorization.

## Approved TASK Plan and Coverage

John approved the complete three-TASK split, initially written with kind chore and needs-info status:

| TASK | Order | Delivered outcome | Actual prerequisite / local blockers |
| --- | --- | --- | --- |
| [TASK-00128](../tasks/00128-TASK.md) | 307 | Specific Query handler results and permitted annotated caller contracts through real fetch/message dispatch, metadata and all 22 current registrations. | Operator-amended mixed bus contract; actual supported tagged API minimum/ordinary proof, no generic supplier hold. |
| [TASK-00129](../tasks/00129-TASK.md) | 308 | Single Command declaration, flat owned extraction annotation and real routing/void proof for all 37 baseline handlers. | Generic and mismapped-configuration holds superseded by John's trusted matching-map decision; qualify existing tagged Common minimum/ordinary routes. |
| [TASK-00130](../tasks/00130-TASK.md) | 309 | Exact payload/metadata/void Command contracts and outcome Query-result typing for Agent issuance. | Qualified/tagged generic Command/Query typing APIs plus actual conversion supplier [TASK-00182](../tasks/00182-TASK.md). No Command-result API required. |

The original source-name manifests account for 22 QueryHandlers and 37 CommandHandlers. TASK-00129's refreshed
candidate retains those 37 Command paths plus two issuance handlers owned by TASK-00130. These are planning inventories,
not exhaustive source audit, generic/runtime proof or completed supplier/consumer qualification. Refresh candidates
and follow actual moved/new/removed owners. TASK-00130 is the distinct conversion-specific Command/Query typing slice,
not permission to implement its supplier or add placeholder handlers. The earlier result-bearing premise is superseded.

TASK-00128/00129 have no demonstrated local blocker. TASK-00130 now has blocked_by TASK-00182, whose accepted conversion
supplies the actual production paths; no reverse dependency. Actual external generic-typing evidence remains needs-info,
not a fictitious local TASK ID or a result-capable-bus requirement. Orders 307–309 and unrelated priorities are unchanged.
Missing generic APIs do not block TASK-00182's ordinary functional conversion; missing Query generics do not block
independent Command adoption. No blanket wait on TASK-00110, every Domain/query correction or architecture refactor.

TASK-00110 retains existing canonical migration. Coordinate imports/minima/shared fixture plumbing once rather than
copy that adoption. TICKET-00039/00040 own result/absence/failure behavior and adapt affected declarations/callers/
dispatch fixtures in their complete changes; this ticket owns the generic relationship. Do not certify today's
nullable signatures as the approved required-result destination or retain them as a compatibility mode. TICKET-00042
retains whole-library enforcement and final reconciliation; each dispatch slice owns complete focused proof, current
examples, full gate/exact owned coverage, logs and independent Review/behavioral QA. No new fixture framework is required.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 307 | [TASK-00128](../tasks/00128-TASK.md) | Preserve types through Query dispatch | done |
| 308 | [TASK-00129](../tasks/00129-TASK.md) | Preserve Command payload types through dispatch | done |
| 309 | [TASK-00130](../tasks/00130-TASK.md) | Qualify Agent issuance Command and Query dispatch | needs-info |
## Decisions and Progress

John subsequently selected single supported Command/Query declarations and flat local payload annotations in the
owning handler (and analogous Event-listener annotations). TASK-00129 owns only the 37 baseline Command paths;
TASK-00128/00130/00136 keep their distinct implementation/acceptance scopes. Command generic supplier holds and
prohibitions on the selected owned annotations are superseded, including contradictory historical wording below.
This does not waive correct runtime routing qualification or imply Query-result inference. John's subsequent
disposition accepts trusted matching maps and focused expected-registration unit assertions. The retained Common
v1.5.0 mismapped-registration probe describes out-of-contract configuration, not a current hold or supplier defect.
Earlier planning checkpoints below remain history.

John approved this requirement within the six-TICKET split, then the complete three-TASK plan now written as
TASK-00128–00130. At that original checkpoint all remained needs-info, not executable adoption. Inspected installed Common v1.4.0 exposes mixed
Query results and void/unparameterized Command relationships; that observation proves neither the availability nor
absence of newer qualified/tagged APIs. Actual supplier evidence/minima and the future conversion prerequisite remain.

No implementation, source/dependency/consumer mutation, product build, new branch/worktree, commit/publication,
independent acceptance or release follows from writing these records. Earlier TASK scope/order/blockers and Board
priority are preserved; choose the implementation checkout explicitly before any future execution.

At this three-TASK checkpoint, planning generation/read-only validation passed (194 records, 68 active), plus
documentation links and diff/record whitespace checks. The new name manifests account for 22/22 QueryHandlers and 37/37 current CommandHandlers; both
future Agent use cases were assigned to TASK-00130 under the original result-bearing premise; that premise is now
superseded by John's void-Command/outcome-Query amendment without claiming converted handlers exist.
This is planning coverage, not generic/runtime/supplier/consumer qualification; no product build was run.
The Board still selects TASK-00107 first.

TICKET-00039's subsequent five-TASK plan is now approved/written as TASK-00131–00135, with its ResultSet supplier
planning gap held explicitly rather than absorbed into this ticket's generic adoption.

TICKET-00040's revised ten-TASK plan is now approved/written as TASK-00136–00145. Its remaining Event typing hold
is distinct from this ticket's Query/Command adoption; known local callback-result @var with native UoW mixed is
explicitly accepted and creates no UoW supplier prerequisite.

TICKET-00041's four-TASK plan is now approved/written as TASK-00146–00149; profile qualification consumes this
Query inference supplier through its real TASK-00128 edge, without expanding this ticket's scope.

TICKET-00042's three-TASK plan is now approved/written as TASK-00150–00152. Minimal fixture machinery supports,
not duplicates, this ticket's dispatch semantics; final reconciliation consumes all three real qualification tasks.

TICKET-00045's separate administration audit plan is now approved/written as TASK-00153–00157.
TICKET-00046's separate four-TASK Feature/policy audit plan is now approved/written as TASK-00158–00161.
TICKET-00047's four-TASK audit plan is now approved/written as TASK-00162–00165.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
EPIC-00015's added conversion is now fully planned as TICKET-00053/TASK-00182. TASK-00130 has its real supplier edge
and its amended void-Command/outcome-Query qualification; actual generic/canonical supplier evidence remains separate.
Next: `/skill:next` for current Board selection; no implementation starts here.
