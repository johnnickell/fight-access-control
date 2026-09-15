# Planning Conventions

This document defines the canonical planning structure for Fight project repositories.
Use these conventions consistently across all projects.

## Directory Structure

```
planning/
  adr/              Architecture Decision Records
  agents/           Domain-specific agent instructions
  epics/            High-level work destinations
  specs/            Product Requirements Documents (Tasks)
  tasks/          Executable work items
  wayfinder/        Pre-implementation investigation maps and decision tasks
  ROADMAP.md        Strategic progress record
  README.md         Directory guide
```

## File Naming

| Artifact | Prefix | Example |
|----------|--------|---------|
| Epic | `NNNNN-EPIC.md` | `00001-EPIC.md` |
| Ticket | `NNNNN-Ticket.md` | `00003-Ticket.md` |
| Ticket | `NNNNN-TICKET.md` | `00034-TICKET.md` |
| ADR | `NNNN-short-description.md` | `0005-layer-dependency-matrix.md` |
| Wayfinder Map | `descriptive-name-map.md` | `fight-common-release-coordination-map.md` |
| Wayfinder Ticket | `WF-NNN-short-description.md` | `WF-001-release-destination-and-boundaries.md` |
| Wayfinder Research | `WF-NNN-description-research.md` | `WF-014-contract-audit-research.md` |

Identifiers are independent five-digit sequences. Ticket identifiers are displayed as `T-NNNNN`.

Every planning directory keeps its copy-ready template beside its live artifacts. Templates begin with `_`, are
not planning records, and are never assigned an identifier. Copy a template before authoring a new artifact; do
not alter a template to record a live decision.

## Ticket Frontmatter

Every ticket file begins with YAML frontmatter:

```yaml
---
id: TASK-00034
prd: TICKET-00011
title: Brief description of the work
status: ready-for-agent
blocked_by: TASK-00033
---
```

## Ticket Status Lifecycle

| Status | Meaning |
|--------|---------|
| `needs-triage` | Not yet classified |
| `needs-info` | Blocked on a decision or missing evidence |
| `ready-for-agent` | Decision-complete and executable when dependencies are done |
| `ready-for-human` | Requires human judgment or an external action |
| `in-progress` | Actively being changed |
| `done` | Acceptance criteria and verification are complete |
| `wontfix` | Intentionally closed without implementation |

Do not store `blocked` as a status; derive it from unfinished `blocked_by` edges.

## BOARD.md

`planning/tasks/BOARD.md` is the canonical execution frontier. It must be structured as:

- **"What's Next?" Contract** — defines what `/ask-matt` or an unqualified "What's next?" returns
- **Now** — the current human decision requiring judgment
- **Ready Frontier** — rank-ordered tasks with no unfinished blockers
- **Waiting** — `ready-for-agent` tasks with unfinished `blocked_by` edges
- **Needs Info** — tasks waiting on decision authority
- **Recently Closed / Done** — terminal tasks with outcomes

## ROADMAP.md

`planning/ROADMAP.md` records strategic progress with three sections:

- **In progress** — a table of active epics with target version, status, and current outcome
- **Route to `<version>`** — numbered narrative steps describing the path to the next release
- **Completed / Released** — terminal epics and released versions

## Wayfinder Convention

Wayfinder maps are planning-only investigation documents for efforts whose implementation route
is not clear enough for an epic or Ticket yet. Each map:

- Has a `Label: wayfinder:map` header
- Defines a clear destination
- Links to decision tasks (WF-NNN) that produce design decisions
- Has a `Frontier` section showing the next takeable ticket
- Produces an implementation handoff (epic, Tasks, and T- tasks) when complete

Wayfinder tasks (WF-NNN) document design decisions. Research files capture investigation output.
Wayfinder files are never executable implementation tasks.

### Archive Operation — Explicit Command Only

Archiving is a deliberate repository-maintenance operation, never a completion side effect. Run it only when
explicitly asked to archive tasks, specs, epics, a named Wayfinder map, or a named set of those artifacts.
Use `./bin/archive-planning` with a dry run first and `--apply` only after its proposed moves are correct.
The command moves records and rewrites local Markdown links throughout live planning and archive directories;
never move files by hand.

| Request | Command shape | Destination |
|---------|---------------|-------------|
| archive tasks | `./bin/archive-planning tasks TASK-00001 … [--apply]` | `planning/tasks/archive/` |
| archive specs | `./bin/archive-planning specs TICKET-00001 … [--apply]` | `planning/specs/archive/` |
| archive epics | `./bin/archive-planning epics EPIC-00001 … [--apply]` | `planning/epics/archive/` |
| archive a Wayfinder map | `./bin/archive-planning wayfinder map-name [--apply]` | map, tasks, and research under `planning/wayfinder/**/archive/` |

The operation fails closed unless every requested artifact is eligible:

- a ticket is terminal (`done` or `wontfix`);
- a Ticket is terminal and all of its tasks are terminal;
- an epic is terminal and all of its Tasks are terminal; and
- a Wayfinder map is `Closed`, all of its linked decision tasks are `Closed`, its frontier is empty, and its
  resolution links to the resulting epic, Ticket, or implementation ticket handoff.

After an applied archive, run `./bin/planning-check`, inspect the rewritten links, refresh the relevant README
index and Board/ROADMAP projections, and commit the move plus reference repair together. Archives remain
addressable planning records; never renumber, flatten, or replace them with prose summaries.

## Epic Convention

Each epic file has YAML frontmatter with `id`, `title`, `status`, and `target`; lists constituent Tasks; and has
a `Progress` section summarizing completed work.

## Ticket Convention

Tasks describe coherent product requirements. A Ticket README tracks all Tasks with their status.

## Branch and Workflow Convention

- Branches follow `feature/<description>` from `develop`
- Never commit directly to `develop` or `main`
- Coordinate-build scratch lives in gitignored `.runs/`, never in `planning/`
- An approved disposable linked worktree lives at `.runs/<YYYY-MM-DD>-<slug>/worktree/`; cleanup remains a
  separate authorization

## Pre-PR Synchronization Checklist

Before creating a pull request for any feature or bug fix:

1. Update ticket status and verified acceptance criteria.
2. Update `BOARD.md` and recalculate the frontier.
3. Update the parent Ticket, epic progress, and `ROADMAP.md` when affected.
4. Run `./bin/planning-check` and verify `blocked_by` edges.
5. Refresh Wayfinder continuity when a map frontier or handoff changed.
