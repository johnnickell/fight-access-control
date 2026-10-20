# Wayfinder Map: Consolidated AccessControl correction batch

**Label:** `wayfinder:map`
**Status:** Closed

## Destination

Plan one dependency-ordered package batch using existing requirement ownership, with three corrections and one
new Role caller-concurrency capability. Each slice is a behavior-level TASK rather than separate handler/layer work.

**Done** = decisions resolved, requirements accepted and all four implementation handoffs linked. This planning
condition is satisfied; the TASKs themselves remain unfinished.

## Publication boundary

This is the approved public planning summary. Restricted source assessments, detailed scenarios and execution briefs
remain with maintainers under [SECURITY.md](../../SECURITY.md). Authorized implementation and independent review
must obtain the applicable full brief; lack of access is a private handoff, not permission to weaken acceptance.
This map makes no affected-version, impact, exploitability or newly executed behavioral-verification claim.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-029 | [Package operation outcome contract](tickets/WF-029-failure-evidence-and-outcomes.md) | Grilling | HITL | **Closed** | — |
| WF-030 | [Recovery lifecycle contract](tickets/WF-030-recovery-continuity-contract.md) | Grilling | HITL | **Closed** | — |
| WF-031 | [Custom Role caller-concurrency ownership](tickets/WF-031-role-caller-concurrency.md) | Grilling | HITL | **Closed** | — |

John approved the consolidated decisions and four-slice direction. Existing EPIC-00001/TICKET-00001 historical
closeouts remain intact; their new children own unfinished work. TICKET-00007's delivery constraints are retained.

## Accepted handoff

| Slice | Owner | TASK | Blockers |
|---|---|---|---|
| B1 — Package operation outcomes | [TICKET-00001](../tickets/00001-TICKET.md#approved-correction-batch-amendment) | [TASK-00077](../tasks/00077-TASK.md) | — |
| B2 — Recovery lifecycle | TICKET-00001 | [TASK-00078](../tasks/00078-TASK.md) | TASK-00077 |
| B3 — Policy preview consistency | TICKET-00001 | [TASK-00079](../tasks/00079-TASK.md) | TASK-00077 |
| B4 — Role caller revisions | [TICKET-00024](../tickets/00024-TICKET.md) | [TASK-00080](../tasks/00080-TASK.md) | TASK-00077, TASK-00079 |

B2 precedes B3 by priority, not functional dependence. B3→B4 is integration sequencing for the shared Role/policy
boundary. External consumer work and release approval are not local TASK dependencies.

## Frontier

None. All decisions and requirement/TASK decomposition are complete.

## Verification and consumer handoff

Bugs require meaningful failing behavioral regressions before repair. New capabilities use contract tests without
claiming a previous promise. Implementation requires focused checks, the full canonical gate, exact coverage,
independent Review and adversarial nonvisual QA. Restricted evidence stays private; completion summaries are sanitized.

One consolidated consumer handoff covers current persistence and transaction contracts, payloads, worker composition,
Role revision propagation and qualification obligations. Consumers own production adapters, transport, permissions,
schema deployment and actual runtime/database/provider evidence. Package checks do not establish consumer readiness.

Follow [ADR 0011](../adr/0011-pre-v1-current-contract-only.md): current contracts only, no historical compatibility
framework. A coherent candidate may be considered after all slices qualify; no version, release, support window,
public disclosure or deployment is selected here.

## Next

`/skill:next` consults the [Board](../tasks/BOARD.md). Planning closure does not start implementation or select a
checkout. No further policy interview, new EPIC, consumer change or reopening of historical closeouts is required.
