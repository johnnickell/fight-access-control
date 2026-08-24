# Issue Tracker

Resolve work from the canonical file in `planning/tickets/`, never from an inferred PRD or GitHub number. Before
implementation, confirm its acceptance criteria, dependencies, branch, seams, and verification commands.

A ticket is executable only when its status is `ready-for-agent` and every `blocked_by` ticket is terminal. Use
`.runs/` for coordinate-build scratch and copy durable outcomes back into the ticket. When an approved task needs
isolation, create its disposable linked worktree under `.runs/<YYYY-MM-DD>-<slug>/worktree/`; run from that
checkout and remove it only with separate cleanup authorization.

Keep the ticket, board, PRD, epic, and roadmap synchronized. A ticket becomes `done` only after acceptance
criteria pass, `./bin/planning-check` and `./bin/build` are green, durable evidence is recorded, and its board
projection is synchronized.
