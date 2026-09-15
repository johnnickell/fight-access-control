# Planning

This directory is the committed source of truth for Fight AccessControl planning.

- [ROADMAP.md](ROADMAP.md) records strategy and generated EPIC status.
- [epics/](epics/) describes business destinations.
- [tickets/](tickets/) holds coherent product requirements under EPICs.
- [tasks/](tasks/) contains executable vertical slices and the generated execution Board.
- [adr/](adr/) records architectural decisions.
- [agents/](agents/) holds project-specific working guidance.
- [wayfinder/](wayfinder/) holds planning-only investigation maps, decision tickets, and research.
- [provenance/](provenance/) retains immutable bootstrap evidence and is not current delivery authority.
- [MIGRATION.md](MIGRATION.md) maps retained historic PRD and T identifiers to their live records.

EPIC, TICKET, and TASK identifiers are independent five-digit sequences. Every live migrated record remains in place;
legacy identifiers are provenance, not aliases for new work. Statuses are needs-triage, needs-info,
ready-for-agent, ready-for-human, in-progress, done, and wontfix. Blocking is derived from unfinished TASK
blocked_by edges.

[CONVENTIONS.md](CONVENTIONS.md) defines naming, lifecycle, generated views, Wayfinder maps, explicit archive
operations, and pre-PR synchronization. After changing a planning record, run:

    ./bin/planning-check --write
    ./bin/planning-check

Coordinate-build scratch belongs in ignored .runs/. Approved disposable linked worktrees live beneath their run
directory and are removed only with separate cleanup authorization.
