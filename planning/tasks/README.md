# Tasks

Tasks are executable vertical slices. Their `ticket` frontmatter connects them to a parent Ticket, and `blocked_by` contains only Task IDs. The [Task Board](BOARD.md) is the operational projection.

All 32 migrated Tasks remain live and terminal. Run `./bin/planning-check --write` after record edits, then rerun it read-only.
