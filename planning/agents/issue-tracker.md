# Issue Tracker

Resolve work from the canonical TASK file in `planning/tasks/`, never from an inferred TICKET or GitHub number. Before
implementation, confirm its acceptance criteria, dependencies, branch, seams, and verification commands.

A TASK is executable only when its status is `ready-for-agent` and every `blocked_by` TASK is terminal. Use
the purpose-named `.runs/` subdirectories in the [project profile](project-profile.md#tests-and-delivery) for scratch
and copy durable outcomes back into the TASK. Isolated TASK source checkouts stay outside `.runs/`; run from
that checkout and follow the ownership-qualified lifecycle and guarded removal instructions in
[local tooling](../../docs/engineering/LOCAL_TOOLING.md).

Keep the TASK, Board, parent TICKET, EPIC, and Roadmap synchronized. A TASK becomes `done` only after acceptance
criteria pass, `./bin/planning-check` and `./bin/build` are green, durable evidence is recorded, and its board
projection is synchronized.
