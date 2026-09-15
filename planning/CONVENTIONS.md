# Planning Conventions

This repository's durable planning model is **EPIC → TICKET → TASK**. It is the local authority for planning
structure, lifecycle, generated views, Wayfinder continuity, and the explicit archive operation.

## Directory Structure

    planning/
      adr/              Architecture Decision Records
      agents/           Focused project guidance
      epics/            Business destinations
      tickets/          Related use cases and requirements
      tasks/            Executable vertical slices and generated Board/index
      wayfinder/        Pre-implementation maps, decision tickets, and research
      ROADMAP.md        Strategy plus generated EPIC status projection
      MIGRATION.md      Historic PRD/Ticket identity map

## Records and naming

| Level | Identifier | File | Parent |
| --- | --- | --- | --- |
| EPIC | EPIC-NNNNN | planning/epics/NNNNN-EPIC.md | — |
| TICKET | TICKET-NNNNN | planning/tickets/NNNNN-TICKET.md | epic: EPIC-NNNNN |
| TASK | TASK-NNNNN | planning/tasks/NNNNN-TASK.md | ticket: TICKET-NNNNN, or standalone bug/chore |
| Wayfinder decision | WF-NNN | planning/wayfinder/tickets/WF-NNN-*.md | its map |

Each level owns an independent five-digit sequence. Preserve numbers, gaps, terminal records, and legacy_id
metadata. Historic PRD-NNNNN and T-NNNNN names are provenance only; see [MIGRATION.md](MIGRATION.md).

Every record directory keeps a copy-ready template beginning with _. Templates are not records and never receive
an identifier.

## TASK frontmatter and lifecycle

    ---
    id: TASK-00034
    ticket: TICKET-00011
    order: 34
    title: Brief executable slice
    status: ready-for-agent
    blocked_by: TASK-00033
    ---

Valid statuses are needs-triage, needs-info, ready-for-agent, ready-for-human, in-progress, done, and wontfix.
Blocking is derived from unfinished blocked_by TASK edges, not stored as a status.

TASKs normally declare a live or archived `ticket: TICKET-NNNNN` parent. A standalone repair that does not belong
to an existing Ticket leaves `ticket` empty and declares `kind: bug` or `kind: chore`; it retains the normal TASK
order, lifecycle, acceptance, verification, blocker, and optional PR metadata. Do not invent a Ticket or EPIC
parent for this exception.

## Generated planning views

Task frontmatter is canonical. planning/tasks/README.md, planning/tasks/BOARD.md, EPIC/TICKET child lists, and the
Roadmap record-status projection are deterministic views. After a planning-record edit, run:

    ./bin/planning-check --write
    ./bin/planning-check

The write form regenerates views; the read-only form validates IDs, parents, dependencies and cycles, local links,
ignored run space, and view freshness. Do not hand-edit generated rows.

The Board has the contract used by /ask-matt: surface a current human decision first and the active TASK when one
exists; otherwise surface the first ready TASK. It separates In Progress, Ready Frontier, Waiting, Needs Info,
Human Action, Triage, and Recently Done records.

## Wayfinder

Wayfinder maps document uncertainty before implementation. They link decision tickets under
planning/wayfinder/tickets/, contain one authored frontier, and produce an EPIC/TICKET/TASK handoff when closed.
Wayfinder decision tickets are planning records, not executable TASKs.

## Archive operation — explicit command only

Archiving is deliberate maintenance, never a completion side effect. Only archive on an explicit request. First
review the dry run, then use --apply only after the proposed moves are correct:

| Request | Command | Destination |
| --- | --- | --- |
| TASKs | ./bin/archive-planning tasks TASK-00001 … [--apply] | planning/tasks/archive/ |
| TICKETs | ./bin/archive-planning tickets TICKET-00001 … [--apply] | planning/tickets/archive/ |
| EPICs | ./bin/archive-planning epics EPIC-00001 … [--apply] | planning/epics/archive/ |
| Wayfinder map | ./bin/archive-planning wayfinder map-name [--apply] | planning/wayfinder/**/archive/ |

The tool fails closed unless selected records are terminal: a TICKET also requires terminal child TASKs, an EPIC also
requires terminal child TICKETs and TASKs, and a Wayfinder map requires closed decisions, an empty frontier, and an
implementation handoff. It repairs local Markdown links when applying. After an applied archive, regenerate and
validate planning views before committing the move.

## Delivery hygiene

- Branch feature work from develop; never commit directly to develop or main.
- Keep run coordination in ignored .runs/; preserve it until separate cleanup authorization.
- Before a feature PR, update the owning TASK, generated views, parent TICKET/EPIC progress, Roadmap if strategic
  progress changed, and verify no downstream blocked_by edge remains unfinished.
