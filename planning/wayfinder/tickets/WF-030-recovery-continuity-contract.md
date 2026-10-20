# Recovery lifecycle contract

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Planning approved; implementation and disclosure remain separate
**Map:** [Consolidated AccessControl correction batch](../access-control-correction-batch-map.md)
**Depends on:** —

## Decision

John approved the complete package recovery-lifecycle contract, including the bounded policy extensions and
preservation of supported recovery journeys. [TICKET-00007](../../tickets/00007-TICKET.md)'s delivery boundary and
consumer-owned persistence, transport and worker responsibilities remain intact.

The detailed transition matrix, accepted criteria and source assessment remain in the maintainer brief under
[SECURITY.md](../../../SECURITY.md). This public summary neither narrows those requirements nor assesses a released
version. Implementation and independent review must use the full brief; unavailable access is a private handoff,
not permission to infer missing requirements.

## Handoff

[TICKET-00001](../../tickets/00001-TICKET.md#approved-correction-batch-amendment) owns the requirement and
[TASK-00078](../../tasks/00078-TASK.md) owns the accepted implementation handoff, blocked by TASK-00077.
No further policy interview is required. No implementation, verification or consumer qualification is claimed.
