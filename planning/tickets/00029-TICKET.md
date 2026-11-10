---
id: TICKET-00029
epic: EPIC-00014
title: Compose scoped execution and protected-detail access
status: ready-for-agent
---

# Compose scoped execution and protected-detail access

## Approved Refactoring Amendment

[EPIC-00015](../epics/00015-EPIC.md)'s canonical adapter/consumed-Common baseline reaches TASK-00089/00090 through
TASK-00084, which now consumes [TASK-00108](../tasks/00108-TASK.md) and [TASK-00110](../tasks/00110-TASK.md).
Use actual Common Query/admission contracts through configured buses and canonical MCP adapters, not copied readers
or retired lifecycle/Store facades. The [Common v1.5.0 amendment](../epics/00014-EPIC.md#common-v150-readiness-amendment)
clears scoped/protected-read supplier holds; independent grants and implementation-time minimum/ordinary-lane tests remain.

## Problem and Outcome

An Agent allowed to invoke `execution.list` or `execution.detail` is not thereby allowed every project, session,
reference or captured body. Consumers need a clear reusable composition between AccessControl Permission gates
and Common's scoped Query admissions, without the package inventing their project membership or sharing policy.

Compose the actual execution/phase/activity and protected-detail use cases with independent consumer-owned scope,
metadata and body decisions. Prove the complete admitted Tool-to-Query journey using the same owning Common Queries,
not an alternate reader or data policy. This is item 5 of [EPIC-00014](../epics/00014-EPIC.md).

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Integrator composes capability admission and scoped readers | N/A; composition | Keep Tool requirements separate from trusted relationship grants; no implicit all-project/body scope. |
| Agent browses execution summaries/pages/phases/activity | Existing Common execution Queries | Apply current Permission/scope decisions to selected rows, full-selection totals and permitted reference metadata. |
| Agent reads captured messages or a detail chunk | Existing Common protected Queries | Check independent metadata/body access for each page/chunk; body-only authority cannot bypass required metadata. |
| Agent supplies forged cross-project/session/phase/activity/detail identities | Owning Query rejects/conceals under Common's contract | Selection fields confer no authority and cannot escape the consumer's permitted relationship scope. |
| Permission, relationship access or stored content changes between requests | Fresh owning Query admission | Later pages/chunks observe current access and actual availability, not a lease from prior results. |

Inspection emits no new Domain events, changes no business state and dispatches no mutation Command. Existing
Common handlers/readers own Query selection, read-side limits and truthful absence/capture semantics. Authorized
protected bodies may appear only in the explicitly admitted response, never in safe timeline output or diagnostics.

## Requirements, Validation and Permissions

- Use [TICKET-00025](00025-TICKET.md) for coarse execution Tool admission, then actual Common execution/detail
  admission ports for target-aware decisions. Coarse admission alone must not construct an unrestricted access object.
- Reusable AccessControl composition gates applicable direct Permissions; consumers supply authoritative relationship
  scope. Specify the useful helper/delegation boundary downstream without adding a tenant/project/Task model.
- Preserve separate execution/timeline, detail-reference/metadata and body grants. Do not infer detail access from
  timeline permission; do not unnecessarily veto a separately admitted protected read solely because timeline access
  is absent. Body grants remain subordinate to required metadata admission.
- Apply permission/scope to aggregate totals as well as rows before disclosure. Avoid a global read/count followed
  by presentation-only filtering or concealed reference counts. Protect producer/identity/relationship metadata too.
- Validate actual Common selection relationships and finite pagination/chunk bounds. Wrong-scope and unknown data
  retain Common's safe concealment/nullable results; storage or policy failure is not fabricated empty success.
- Direct Query, worker, console and other paths need the same applicable business admission, even without a Tool
  invoker. Consumer context is trusted, never `_meta`, caller permission arrays or a claimed administrator label.
- Keep ordinary request-local authority and fresh later requests. References, historical capture, a previous page
  and a content chunk do not grant subsequent access, continuous storage or complete-capture guarantees.
- Preserve the actual selected tagged Common output schemas, exact safe projections, finite encoded output and
  protocol error handling. Common v1.5.0 includes TASK-00205's optional typed activity target/timing/result facts in the existing
  activity output; TASK-00089 owns their scoped qualification when supplied. Those facts create no reference/body
  grants, stable history, automatic capture or business-success evidence. TASK-00090 remains independent and must
  retain metadata-only results without requiring body Permission at coarse Tool admission. AccessControl must not
  duplicate upstream values/schemas, SQL, shared Query business logic or protected body retrieval.

## Dependencies and External Gates

- TICKET-00025 supplies Tool registration/availability for the complete execution-Tool journey; these requirements
  do not block generic Tool bindings on a particular consumer data policy.
- Common v1.5.0 supplies `ExecutionReadAdmission`, `DetailReadAdmission`, scoped access types, owning Queries and
  TASK-00205's optional typed activity facts. TASK-00110 records canonical supplier adoption; feature-specific minimum/
  ordinary-lane tests remain implementation acceptance work. No new standalone supplier blocker is needed, and Common
  release does not wait for this consumer's binding/scoped-read implementation or Agent OS adoption.
- [TICKET-00028](00028-TICKET.md) supplies revalidation if a consumer later performs repeated live disclosure or
  background continuation; ordinary paged/chunked request admission does not depend on adding a live transport.
- Actual consumer relationship/persistence/transport composition and race qualification remain separate from package
  fixtures. Follow ADR 0010's consumer policy boundary and the EPIC's single combined-release gate.

## Acceptance Evidence

- [ ] Real Common execution Tools/Queries compose with actual AccessControl authority checks and controlled trusted
  consumer scope, without copying selectors into broad grants or duplicating readers.
- [ ] Capability allowed/data denied, metadata-only, body-only, independently admitted detail and fully permitted
  cases preserve distinct outcomes and no unauthorized body/reference/aggregate disclosure.
- [ ] Forged complete relationships, cross-project/session access, paging/chunk repeats after access changes and
  direct Query paths cannot bypass current business admission.
- [ ] Denial before protected read/opening is measured; authorized reads preserve actual Common projection,
  nullable/missing/retention semantics, complete encoded bounds and safe diagnostics.
- [ ] Inspection commits no business mutation and emits no checking event; tests assert outcomes, not only stub
  Permission returns or call order. Consumer relationship/race qualification limits remain explicit.
- [ ] Dependency-minimum composition, documentation, focused tests, exact coverage, full package gate and later
  independent Review/behavioral QA cover owned integration and its permitted/denied/fault paths.

## Exclusions

Application project/session/Task membership storage, duplicate Queries/SQL, protected-content storage/capture,
retention policy, blanket administrative grants, live protocol mechanics, consumer code changes and deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 89 | [TASK-00089](../tasks/00089-TASK.md) | Compose scoped execution and timeline reads | ready-for-agent |
| 90 | [TASK-00090](../tasks/00090-TASK.md) | Compose independent protected-detail metadata and body reads | in-progress |
## Decisions and Progress

John approved item 5 of the eight-TICKET split, explicitly invoked To Tasks for this TICKET, then approved two
complete journeys. Its full accepted decomposition is now written:

- [TASK-00089 — Compose scoped execution and timeline reads](../tasks/00089-TASK.md), consuming TASK-00084.
- [TASK-00090 — Compose independent protected-detail metadata and body reads](../tasks/00090-TASK.md), also consuming
  TASK-00084, not TASK-00089. Protected detail remains independently admissible without timeline access.

Both are ready-for-agent with the Common v1.5.0 information holds cleared; their shared unfinished TASK-00084
implementation dependency remains. Consumer relationship policy is a configured port input tested with controlled
fixtures, not missing operator input or a production adoption prerequisite. Each owns real Tool/Query/handler/reader composition, direct Query protection,
scope/projection/totals, fresh pages/chunks, safe truthful faults, tests/docs/gates and independent Review/QA.
No separate layer or verification-only TASK is planned. Ordinary request reads do not depend on TASK-00088/live
transports; actual consumer relationship/persistence/race qualification remains separate.

John accepted the external-Tool architecture recommendations and requested reconciliation without duplicate work.
TASK-00084 retains coarse binding; TASK-00089 qualifies applicable safe activity facts; TASK-00090 preserves independent
structural/metadata/body admission. No new TASK, grant, dependency edge or Common release prerequisite is introduced.
The former needs-info supplier holds are cleared by v1.5.0. Feature minimum/ordinary-lane qualification and the
combined-release boundary remain implementation/release obligations, not missing information.

This TICKET's TASK planning is complete, not implementation. TICKET-00030's approved TASK-00091–00096 plan is now
written, as is TICKET-00031's TASK-00097–00102 plan. TICKET-00032's approved TASK-00103–00106 plan is also written.
All EPIC-00014 map planning phases/full TASK plans are accepted/complete; consult the Board for execution selection.
No source/dependency/config change, grants, implementation, publication, release or consumer adoption is authorized
by planning approval. Preserve the combined release, unassigned version and Closed map; continue remaining planning.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
