# Custom Role caller-concurrency ownership

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Package ownership approved for planning
**Map:** [Consolidated AccessControl correction batch](../access-control-correction-batch-map.md)
**Depends on:** —

## Decision

John selected package-owned expected revisions for custom Role rename/remove as a new capability. The contract
covers revision exposure, required command input, all-writer participation, validated persistence, safe conflicts,
no-op behavior and non-repeating concurrency identity. Consumers retain authorization, transport/UI propagation,
confirmation and actual persistence qualification. The consumer-owned alternative was not selected.

Preserve existing managed Role, reference and membership rules. Follow the current-contract-only policy in
[ADR 0011](../../adr/0011-pre-v1-current-contract-only.md); do not add compatibility defaults or a generic revision
framework. No retrospective defect or released-version assessment is implied by this capability decision.

## Handoff

[TICKET-00024](../../tickets/00024-TICKET.md) owns the requirement under EPIC-00001.
[TASK-00080](../../tasks/00080-TASK.md) owns its complete accepted implementation handoff, blocked by TASK-00077
and TASK-00079. This completes the map's planning phases, not implementation, consumer adoption or release.
