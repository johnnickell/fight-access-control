# Tickets

Ticket files are canonical for implementation scope, status, dependencies, acceptance, verification, and durable
evidence. [BOARD.md](BOARD.md) ranks the execution frontier; it never replaces the ticket's `blocked_by` edges.

| Parent PRD | Tickets | Current frontier |
| --- | --- | --- |
| [PRD-00001 — Identity and Authentication Lifecycle](../specs/00001-PRD.md) | [T-00001 through T-00013](BOARD.md#ready-frontier) | [T-00005 — Secure refresh-session rotation](T-00005-secure-refresh-session-rotation.md) |

A `ready-for-agent` ticket is executable only when all `blocked_by` edges are terminal, as defined by the
[issue-tracker rules](../agents/issue-tracker.md). Each ticket belongs to [PRD-00001](../specs/00001-PRD.md).
