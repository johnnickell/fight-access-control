# Planning

This directory is the committed source of truth for Fight AccessControl planning. The package owns its
implementation status locally; adopted provenance does not make another repository authoritative.

| Surface | Authority |
| --- | --- |
| [ROADMAP.md](ROADMAP.md) | Strategic progress and the active epic. |
| [epics/](epics/README.md) | Durable delivery destinations. |
| [specs/](specs/README.md) | Product requirements and behavioral authority. |
| [tickets/](tickets/README.md) | Executable work; each ticket is canonical for status and dependencies. |
| [tickets/BOARD.md](tickets/BOARD.md) | Recommended execution order and the current frontier. |
| [adr/](adr/README.md) | Accepted architecture and quality decisions. |
| [agents/](agents/) | Readiness, triage, architecture, and completion rules. |
| [provenance/](provenance/) | Immutable bootstrap sources, not current delivery authority. |

Ticket identifiers are displayed as `T-NNNNN`; PRDs and epics use `PRD-NNNNN` and `EPIC-NNNNN`. A ticket is
executable only when it is `ready-for-agent` and all `blocked_by` edges are terminal. Run
`./bin/planning-check` after changing any planning artifact. Coordinate-build scratch belongs in gitignored
`.runs/`, never here.
