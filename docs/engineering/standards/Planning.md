# Planning

Individual Markdown records own requirements, status, dependencies, and execution priority. Generated views display them. Read the project's `planning/CONVENTIONS.md` and templates for its adopted schema; do not silently migrate an older project as part of planning a feature.

| Level | Responsibility | Adopted identity/path |
|---|---|---|
| EPIC | Business destination, decisions, boundaries | `EPIC-NNNNN`, `planning/epics/NNNNN-EPIC.md` |
| TICKET | Related use cases and requirements | `TICKET-NNNNN`, `planning/tickets/NNNNN-TICKET.md` |
| TASK | Bounded implementation, normally one PR | `TASK-NNNNN`, `planning/tasks/NNNNN-TASK.md` |
| SUBTASK | Dependency-ordered execution assignment | Ignored run artifacts belonging to a TASK |

Each durable level has its own sequence. Preserve numbers, gaps, and archived identities. Allocate after checking both live and archive records. TICKET has an EPIC parent; TASK has a TICKET parent except a standalone bug/chore. Do not invent parent hierarchy for a small repair or reopen archived parents for unrelated work.

Grilling produces only the EPIC. Decompose EPIC → TICKET → TASK before switching to implementation; support doing both decomposition stages in one requested session. Each TASK normally covers a full use case. Layer-based SUBTASKs are legitimate. A significant SUBTASK can have a separately agreed PR with dependency/merge order and integration ownership recorded; the parent TASK still owns full acceptance.

## Record content and readiness

Every planned use case names actor, trigger, outcome, commands, queries, events, expected side effects, validation, permissions, rejection behavior, and acceptance evidence. Use justified N/A where a concern does not apply. Schema/data changes, compatibility, and failure/recovery consequences are included when relevant. Keep proposed decisions separate from accepted requirements.

Adopted statuses: `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `in-progress`, `done`, and `wontfix`. Ready-for-agent means decisions are complete; executability also requires blockers to be terminal. Ready-for-human identifies an actual next human decision. Done means acceptance and required verification are complete, not that a PR has merged or production has deployed.

Use `blocked_by` for TASK dependencies; preserve edges after completion and derive unfinished blockers. Use deliberate priority (`order`, lower first), then stable IDs only as display tie-breakers. Record PR URLs without treating them as evidence of live merge state. An external blocker belongs in an explicit decision/evidence requirement rather than a fake TASK ID.

## Generated navigation

The Board uses consistent columns: **Order | TASK ID | Title | Parent TICKET | Status | Blocked by | PR**. Parent and blocker links show actual IDs; parents also show titles. Include active work, ready frontier, waiting, missing information, human action, triage, and recently closed sections. For next work, first surface current human decisions and the active TASK, otherwise the first executable ready TASK.

If none of those applies, surface the first `needs-info` TASK, then the first `needs-triage` TASK, using the established priority order within each state. Label that work non-executable and identify the information or triage needed. If only dependency-blocked unfinished work remains, report waiting and its unmet blockers. Unfinished work, executable work, and work needing attention are distinct: claim that no unfinished TASK exists only when every TASK is terminal or the portfolio is empty. Next-action guidance must agree with the Board's state sections and the authoritative records.

Roadmap strategy remains authored; its live EPIC status table is generated. EPIC/TICKET child status tables and indexes derive from records. For Wayfinder maps, show every owned decision, including closed items, with **Decision ID | Title | Type | Mode | Status | Depends on**. Keep decision identity distinct from implementation TICKET/TASK identity. One authored frontier states the next uncertainty to resolve; it must agree with recorded state.

Use the project generator after record changes, then its read-only check. Fight Common's adopted commands are `./bin/planning-check --write` followed by `./bin/planning-check`; inspect other project commands rather than assuming they exist. Validate parents, links, IDs, dependency cycles, archive state, and stale views. The product gate checks but does not rewrite generated views.

Archive planning only on an explicit request using the owning tool, a reviewed dry run, then apply. Require selected records/children terminal as appropriate. Preserve history and repair local links; immutable receipts retain their original identity with a migration map. Run-artifact archival is separate from durable planning archival.
