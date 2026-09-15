# Planning Migration: EPIC -> TICKET -> TASK

Adopted 2026-09-15. This is a taxonomy and navigation migration only: it does not archive, reopen, fabricate, or alter any product/runtime/release record.

| Before | After | Compatibility treatment |
| --- | --- | --- |
| PRD-NNNNN in planning/specs/ | TICKET-NNNNN in planning/tickets/ | Original identity is preserved as legacy_id. |
| T-NNNNN in planning/tickets/ | TASK-NNNNN in planning/tasks/ | Original identity is preserved as legacy_id. |

Link transformation: specs/NNNNN-PRD.md became tickets/NNNNN-TICKET.md; former executable tickets/NNNNN-TICKET.md became tasks/NNNNN-TASK.md. Historical source references under planning/provenance/ retain historic identifiers and point here for the map.

Portfolio preservation: 4 EPICs + 5 Tickets + 32 Tasks = 41 live records. Every record remains live and terminal exactly as before adoption.
