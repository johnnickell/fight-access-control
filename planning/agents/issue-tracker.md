# Issue Tracker

Resolve work from the canonical TASK file in `planning/tasks/`, never from an inferred TICKET or GitHub number. Before
implementation, confirm its acceptance criteria, dependencies, branch, seams, and verification commands.

A TASK is executable only when its status is `ready-for-agent` and every `blocked_by` TASK is terminal. Use
`.runs/` for coordinate-build scratch and copy durable outcomes back into the TASK. When an approved TASK needs
isolation, create its disposable linked worktree under `.runs/<YYYY-MM-DD>-<slug>/worktree/`; run from that
checkout and remove it only with separate cleanup authorization.

Keep the TASK, Board, parent TICKET, EPIC, and Roadmap synchronized. A TASK becomes `done` only after acceptance
criteria pass, `./bin/planning-check` and `./bin/build` are green, durable evidence is recorded, and its board
projection is synchronized.
