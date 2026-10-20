# Planning Conventions

## Lightweight work routing

For “What's next?”, `next`, `/skill:next`, or an unqualified `/ask-matt`, use the routing entry in root AGENTS.md.
Honor an explicit narrower user selection; otherwise preserve Board priority.
This read-only path precedes general orientation; full implementation, review, QA and delivery requirements
still apply when the selected workflow is invoked.

1. Run the documented read-only planning validator once when available. Failure stops selection with the
   actual error and planning-owner handoff; do not refresh views. Without a validator, disclose that limitation
   and check selected record metadata against the authored Board; do not claim generated freshness.
2. Read only the Board's current human decision, active work, and (if no active work) first ready entry.
   Use local section names and authoritative order. Return all active TASKs, not a new winner. Inspect selected
   TASK metadata and its current handoff, plus blocker metadata only as needed. An unresolved contradiction
   is a named input gap, not permission to audit every record or infer acceptance from `done`.
3. Read the Wayfinder index's map-status/frontier inventory. Closed or decision-complete maps need no historical
   reread. For active/open maps, read only current frontier and decision/dependency status or explicit external
   prerequisite evidence needed for availability. Unknown state or an absent frontier needs its owner's input.
   Multiple available maps remain independent choices; no global priority is implied.
4. Use the Board/Roadmap's current planning handoff for unfinished decomposition, including closed maps.
   Verify the named phase in the owning record's current progress/approval section. Map closure, parent status
   and child counts do not prove complete decomposition. If the handoff is missing or contradictory, report
   the missing phase/owner; do not reconstruct it by reading every closed map and descendant.
5. Read canonical review/QA identity and disposition only when they change the selected route. Unresolved
   identity is an evidence handoff, never acceptance. Confirm a suggested skill from supplied capability
   metadata/bindings; load its full instructions and references when invoked, not merely to recommend it.

Prefer heading searches and bounded section reads. Aim to retrieve no more than about 24,000 characters
of routing evidence; stop with a precise unresolved input before exceeding that scope unless the user requests
a deeper audit. Do not read append-only task histories, other worktrees' entire tasks, general architecture,
or all receiving skills to produce a recommendation. Return TASK, WAYFINDER, and relevant PLANNING/HUMAN INPUT;
stop without invoking a workflow or changing files. Missing indexes are reported, not fabricated.

During authorized planning updates, keep current Board/Roadmap handoffs source-linked and concise: target,
phase, pending decision/owner. Keep Wayfinder inventory/frontier pointers current. These are navigation aids,
not another backlog or proof of delivery. Preserve generated sections and all historical decisions.

Routing sources: [TASK Board](tasks/BOARD.md), [Wayfinder inventory](wayfinder/README.md), [Roadmap](ROADMAP.md).

## Workflow continuations

Use the actual selected ID in every copyable `Next:` command; `TASK-NNNNN` below denotes that substitution, not
an executable target. A recommendation neither invokes the command nor grants authority. Confirm capability from
supplied provider resources or the [workflow binding](agents/project-profile.md#agent-os-workflow-binding), including
explicit-only skills; load the owning instructions when invoked.

| Established state | Recommend and stop |
| --- | --- |
| Unimplemented approved work, ongoing implementation or confirmed implementation/QA defects | `/skill:build TASK-NNNNN`; preserve the selected checkout, ledger and consumed revision allowance |
| Explicit implementation-only request | `/skill:work TASK-NNNNN` |
| Committed implementation with required verification, awaiting independent acceptance | `/skill:review TASK-NNNNN` in a fresh independent session |
| Current acceptance, but QA or its applicability unresolved | `/skill:qa TASK-NNNNN` in a fresh independent session, including non-UI instructions |
| Current acceptance and applicable QA satisfied, including justified N/A | `/skill:land TASK-NNNNN` within publication authority |
| QA INCOMPLETE or a genuinely missing brief/runtime/tool/decision | Name the concrete missing input and owner; resume the affected stage after recovery |
| Stale/wrong-subject/contradictory Review or QA evidence, or uncertain findings | Evidence owner reconciles the subject or obtains independent reassessment; do not guess acceptance or manufacture edits |
| Initial acceptance lacks only explicitly required hosted proof | Authorized land draft-evidence path, then independent review of the proof; otherwise name missing publication authority |
| Accepted candidate plus permitted administrative closeout, final delivery proof pending | Land verifies delivery and the provenance bridge; no recursive status commit or new review solely for delivery |
| Verified open PR delivered | Human review/merge of the actual PR URL; no automatic merge or next TASK |

Stage handoffs remain narrower than default navigation: Work hands committed changes to independent Review;
Review implementation blockers and QA-confirmed defects hand canonical findings to Work, then fresh Review and
affected QA. An active Build coordinator continues those delegated stages instead of stopping to ask for approvals
already included in invocation. QA INCOMPLETE is evidence recovery, not speculative repair; QA FAIL does not reset
the shared revision allowance. Preserve canonical reports and previous attempts on resume.

Finish each stage with outcome, subject, verification/limitations, durable evidence pointer and an explicit `Next:`
command or concrete prerequisite. Do not replace a known continuation with generic `/skill:next`. Planning handoffs
name the accepted phase and actual EPIC/TICKET/map target; unresolved approval stays human input. The workflow
binding owns invocation authority, target substitutions and evidence locations.

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

## Automatic parent completion

When a TASK becomes `done` or `wontfix`, complete eligible parent TICKETs and then EPICs in the same operation.
Count live and archived children. A live, non-terminal parent with at least one child closes when every child
is terminal: use `wontfix` if every child is `wontfix`, otherwise `done`. Parents without children or with an
unfinished child remain open. Preserve already-terminal and archived parents.

Child acceptance and intentional `wontfix` decisions remain with the child records. Parent completion requires
no separate assessment, independent review, QA, confirmation, or skill invocation. Record any remaining work as
an unfinished child rather than a separate parent-closeout gate. Parent status does not assert review, merge,
release, deployment or publication, and completion never archives records automatically.

Run `./bin/planning-check --write` during completion; it closes eligible parents and refreshes views.
Then run the read-only `./bin/planning-check`. Read-only validation never writes completion metadata.


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

The write form closes eligible parents and regenerates views; the read-only form validates IDs, parents, dependencies and cycles, local links,
ignored run space, and view freshness. Do not hand-edit generated rows.

The Board has the contract used by /ask-matt: surface a current human decision first and the active TASK when one
exists; otherwise surface the active TASK, first executable ready TASK, first needs-info TASK, first needs-triage
TASK, then the empty-portfolio fallback. Needs-info and needs-triage TASKs are not executable. It separates In
Progress, Ready Frontier, Waiting, Needs Info, Human Action, Triage, and Recently Done records.

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
