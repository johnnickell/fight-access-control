# Planning

This directory is the committed source of truth for Fight AccessControl planning.

- `ROADMAP.md` records strategic progress.
- `epics/` describes destinations.
- `specs/` describes coherent product requirements.
- `tickets/` contains executable work; each ticket is canonical for its own status and dependencies.
- `tickets/BOARD.md` ranks the current execution frontier.
- `adr/` records architectural decisions.
- `agents/` contains focused working instructions.
- `wayfinder/` contains planning-only investigation maps and decision tickets for efforts whose
  implementation route is not clear enough for an epic or PRD yet.
- `provenance/` retains immutable bootstrap evidence and is not current delivery authority.

Every artifact directory keeps a `_…_TEMPLATE.md` copy-ready starting point. `wayfinder/README.md` is the
continuity index for charting work and its next decision frontier. Archives remain part of this committed
planning record: use `./bin/archive-planning` only when explicitly asked, review its dry run, then use `--apply`
to move eligible terminal records and repair local Markdown links.

Identifiers are independent five-digit sequences. Ticket identifiers are displayed as `T-NNNNN`. Valid statuses
are `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `in-progress`, `done`, and `wontfix`.
Blocking is derived from unfinished `blocked_by` edges and is not stored as a status.

`CONVENTIONS.md` is the canonical reference for planning structure, file naming, ticket lifecycle, BOARD.md,
wayfinder maps, epics, PRDs, and pre-PR synchronization.

Run `./bin/planning-check` after changing planning files. Coordinate-build scratch belongs in gitignored `.runs/`,
never here. Approved disposable linked worktrees live beneath their run directory at `worktree/` and are removed
only with separate cleanup authorization.
