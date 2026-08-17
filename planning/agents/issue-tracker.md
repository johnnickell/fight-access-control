# Issue Tracker

Resolve work from the canonical file in `planning/tickets/`, never from an inferred PRD, Fight Common ticket,
or GitHub number. Before implementation, confirm its acceptance criteria, `blocked_by` edges, branch, highest
behavioral seam, exclusions, and verification commands.

A ticket is executable only when its status is `ready-for-agent` and every `blocked_by` ticket is terminal
(`done` or `wontfix`). Move active work to `in-progress`. A ticket becomes `done` only after its acceptance
criteria pass, `./bin/planning-check` and `./bin/build` are green, durable evidence is recorded, and its board
projection is synchronized.

Use `.runs/` for coordinate-build scratch and spoke reports; never stage it. Copy durable outcomes and deviations
back into the canonical local ticket. Detailed PRD-00001 capability tickets do not exist during bootstrap; create
them only through the later local `$aios /to-tickets 00001-PRD` workflow after bootstrap authority is green.
