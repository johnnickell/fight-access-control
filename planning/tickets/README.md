# Tickets

Ticket files in this directory are canonical for implementation scope, status, dependencies, acceptance, and
durable evidence. `BOARD.md` ranks executable work.

Each ticket belongs to [PRD-00001](../specs/00001-PRD.md). A `ready-for-agent` ticket is executable only when
all of its `blocked_by` edges are terminal, as defined by `planning/agents/issue-tracker.md`.
