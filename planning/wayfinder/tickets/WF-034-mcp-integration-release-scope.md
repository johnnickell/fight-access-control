# Select the future MCP integration release scope

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Required Common implementations, independent acceptance/qualification and tagged APIs before affected adoption/release; exact minimum versions and framework support ranges require evidence
**Map:** [Permission integration for evolving Common MCP capabilities](../mcp-capability-permission-integration-map.md)
**Depends on:** WF-033, WF-035

## Question

Which permission-integration outcomes should the first future AccessControl release promise, which should wait,
and what approved EPIC destinations and external prerequisites should carry that plan?

## Must decide

- Select a useful initial tranche: Common-owned execution Tool binding, independent protected metadata/body policy
  composition, and any selected Resource/Skill/Prompt bindings. Do not assume every capability must ship together.
- Decide whether live Subscriptions and durable Tasks belong to later destinations conditional on actual Common
  implementation plus the WF-033 revalidation contract. Keep polling independent of live delivery.
- Identify first tagged Common versions containing used APIs and minimum-version qualification; `^1.3` alone does
  not promise that new classes exist. No uncommitted Prompt or hypothetical Task interface is a stable dependency.
- Apply WF-035's selected adapter ownership and safe-error compatibility contract to any framework integration
  tranche. Optional capability-first `src/Adapter` ownership and all five named framework families are approved
  for the combined release; implemented adapters and qualified support are not inferred.
- Approve EPIC destination titles/ownership, deferred capabilities and acceptance boundaries. Keep package evidence
  separate from actual consumer authentication, persistence, scope, runtime/client qualification and adoption.
- Select an exact AccessControl version only if John chooses to do so; otherwise leave it unassigned. Any breaking
  API/minimum narrowing follows the pre-v1 release policy without historical compatibility shims.

## Required evidence

The accepted WF-033 contract, WF-035's adapter/error-composition decision, [capability maturity matrix](../research/fight-common-mcp-permission-integration.md),
current Common implementation/release evidence for any selected slice, and existing AccessControl package/release
boundaries. Recheck changed Common work at handoff; research from an active working tree is not immutable acceptance.

## Accepted interview choices

### One combined release, including Subscriptions and durable Tasks

John selected **B**, not the recommended staged release. The selected future release covers explicit Tool
Permission mappings (including Common's execution Tools), Resource/whole-Skill/Prompt bindings, Subscriptions and
durable Task permission integration, independent consumer scope/protected-body checks, and optional framework
adapters composed with Common's safe-error approach. John subsequently selected all five named framework families
for that combined release, as recorded below.

This release waits for the required Common implementations, independent acceptance/qualification and tagged APIs;
do not ship a reduced initial release by silently deferring Subscriptions/Tasks. Live disclosure and protected
continuation retain WF-033's current-authority checks. Runtime capabilities remain explicitly opt-in, and Task
polling still works without live Subscriptions: sharing a release does not force all integrations to be enabled.

Exact Common minimum(s) remain evidence prerequisites, not invented version numbers. John approved the single EPIC
destination below and explicitly left the AccessControl release number unassigned. Do not assume future PHP
signatures or authorize Composer adoption from approved Common planning. External prerequisites gate the affected
integration/release; they do not prevent completing bounded planning.

### All five framework families in the combined release

John selected **all five**: Slim, Yii 3, Symfony, Laravel and CodeIgniter 4. Reuse shared PSR-15 middleware for Slim
and Yii 3 where the actual contracts fit, with native Symfony subscriber, Laravel middleware and CodeIgniter filter
integration. Follow WF-035's capability-first `src/Adapter` boundary, one inward permission-check implementation
and Common-compatible safe-error composition. Do not silently defer one of these families to a later release.

This is five qualified consumer journeys, not a requirement for five duplicate implementations. Actual supported
version ranges, native ordering/lifetimes, safe failure/reporting, dependency isolation and complete package evidence
must be established before claiming support. Package proof does not authorize or prove deployment/adoption in
Agent OS or any starter. No relative implementation priority among the five was selected.

### Approved EPIC destination and unassigned release number

John approved exactly one EPIC destination: **Permission-Aware MCP Integration and Framework Adapters**, leaving
the AccessControl release number **unassigned**. The EPIC owns the complete combined-release outcome; later
TICKETs/TASKs provide dependency-ordered implementation slices, not a requirement for one oversized PR.

There is no remaining human scope decision in WF-034. Determine actual dependency minima and supported framework
ranges from implemented/accepted/tagged contracts and qualification evidence before adoption; do not ask for or
invent an unknowable future Common version now. No relative implementation priority among frameworks is selected.

Do not add a second protocol endpoint, generic CQRS bridge or Common policy dependency. WF-035 approves optional
integrations inside capability-first `src/Adapter`, not implementation. Reuse Common's selected safe-error
integration rather than competing renderers/reporters.

## Resolution boundary

May approve future outcomes, exclusions, external gates and EPIC destinations. Does not write EPIC/TICKET/TASK
records, authorize implementation, pick consumer adoption, publish, release or deploy. When this map's decisions
are resolved, complete all approved EPIC handoffs before TICKET decomposition, then all TICKETs before TASKs.

## Resolution

Closed after John's approval of the single **Permission-Aware MCP Integration and Framework Adapters** destination
and unassigned release number. The accepted combined release includes Tool/execution, Resource, whole-Skill and
Prompt bindings, Subscriptions and durable Tasks, consumer-owned scope/protected-body checks, Common-compatible
safe errors and all five framework families. WF-033/WF-035 retain tooling/freshness and capability-first Adapter
ownership guarantees. Features remain individually opt-in at runtime; Task polling does not require live delivery.

The release waits for required Common implementation, independent acceptance/qualification and tagged APIs, plus
actual minimum-version and framework-lifecycle evidence. These external prerequisites are not reopened human
scope questions. No implementation, dependency adoption, consumer migration, publication or release is authorized.

John subsequently invoked the approved Grill handoff, now written as
[EPIC-00014 — Permission-Aware MCP Integration and Framework Adapters](../../epics/00014-EPIC.md). All four Wayfinder
decisions and the single EPIC handoff are complete; the map is Closed. John subsequently approved the complete
eight-TICKET requirement split, now written as [TICKET-00025](../../tickets/00025-TICKET.md) through
[TICKET-00032](../../tickets/00032-TICKET.md). Requirement planning is complete. John subsequently approved
TICKET-00025's complete single-TASK plan, now written as [TASK-00084](../../tasks/00084-TASK.md), needs-info for
actual qualified/tagged Common execution contracts and minimum evidence. John then approved TICKET-00026's full
plan, written as [TASK-00085](../../tasks/00085-TASK.md) → [TASK-00086](../../tasks/00086-TASK.md), Resource then
whole-Skill/member admission, independent of TASK-00084. John then approved TICKET-00027's complete plan, written as
[TASK-00087](../../tasks/00087-TASK.md), needs-info for qualified/tagged Common Prompt contracts/minimum evidence,
with no local TASK dependency. John then approved TICKET-00028's full plan, written as
[TASK-00088](../../tasks/00088-TASK.md), ready with no local blocker or new Common runtime API prerequisite.
John then approved TICKET-00029's full plan, written as [TASK-00089](../../tasks/00089-TASK.md) /
[TASK-00090](../../tasks/00090-TASK.md), scoped timeline and independent protected metadata/body journeys, each
consuming TASK-00084 and needs-info for qualified/tagged Common scoped reads/minimum evidence. Neither depends
on the other. John then approved TICKET-00030's six complete journeys, written as
[TASK-00091](../../tasks/00091-TASK.md)–[TASK-00096](../../tasks/00096-TASK.md), all needs-info for qualified/tagged
Common notice/live/publication contracts/minimum evidence plus approved local supplier edges. Prompt absence does
not block ordinary live families; publication is independent of live/Prompt/inspection, and polling needs no live
runtime. John then approved TICKET-00031's six-feature plan, written as
[TASK-00097](../../tasks/00097-TASK.md)–[TASK-00102](../../tasks/00102-TASK.md), all needs-info for qualified/tagged
Common durable Task contracts/minimum evidence plus approved supplier edges. Ordinary polling/input/cancel need no
live runtime; removal does not block complete activation, and full-state live has no extra Prompt/inspection/Mercure
blocker. John then approved TICKET-00032's full plan, written as [TASK-00103](../../tasks/00103-TASK.md) (Slim/Yii 3)
→ native peers [TASK-00104](../../tasks/00104-TASK.md) / [TASK-00105](../../tasks/00105-TASK.md) /
[TASK-00106](../../tasks/00106-TASK.md). All ready-for-agent; peers share order 104 and depend only on the inward shared
contract/first Adapter wiring, not Slim/Yii runtime. No inherited MCP/TASK-00088 hold; native/minimum qualification
remains acceptance work. All map planning phases/full TASK plans are accepted/written (23 features), without
reopening this decision or starting implementation. Actual consumer lifetime/scope/fence/hub qualification remains separate.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
