# Tickets

Ticket files are canonical for status, dependencies, acceptance, and verification. `BOARD.md` ranks executable
work. Terminal ticket sets may move to `archive/` only after their parent PRD closes.

## Active Tickets

| Ticket | Parent | Outcome | Status |
| --- | --- | --- | --- |
| [T-00029](00029-TICKET.md) | [PRD-00004](../specs/00004-PRD.md) | Make Agent Permission changes safe to retry. | ready-for-agent |
| [T-00030](00030-TICKET.md) | [PRD-00004](../specs/00004-PRD.md) | Make User Role changes safe to retry. | waiting on T-00029 |
| [T-00031](00031-TICKET.md) | [PRD-00004](../specs/00004-PRD.md) | Make custom Role Permission changes safe to retry. | waiting on T-00029 |

## Recently Done

| Ticket | Parent | Outcome |
| --- | --- | --- |
| [T-00028](00028-TICKET.md) | [PRD-00003](../specs/00003-PRD.md) | Published the final `SecurityContext` public boundary. |
| [T-00027](00027-TICKET.md) | [PRD-00003](../specs/00003-PRD.md) | Resolved complete Agent authority from a signed request. |
