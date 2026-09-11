# Wayfinder Map: Brief planning destination

**Label:** `wayfinder:map`
**Status:** Active

> This map is an **index, not a store**. Each material decision lives in exactly one linked ticket under
> `tickets/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Describe the implementation-ready planning result.

**Done** = every linked decision ticket is closed, the remaining fog is resolved or excluded, and the map links
to its resulting epic, PRDs, and/or implementation tickets.

## Notes

- State evidence, boundaries, and default working modes.
- Link existing planning artifacts that are navigation evidence rather than settled authority.
- Use exactly one canonical Wayfinder type per ticket: Research, Prototype, Grilling, or Task. Research is AFK;
  Prototype and Grilling are HITL; Task is AFK or HITL according to who must act.
- Keep ticket status exactly `Open` or `Closed`. Show `frontier`, `waiting`, or `gated` as derived map/Board state,
  never as part of ticket status.
- Put only local Wayfinder-ticket prerequisites in `Depends On`. Put releases, external repositories, human approval,
  credentials, or other outside authority in `Gate`.

## Decisions so far

1. **[Decision title](tickets/WF-NNN-decision.md) is open.** State the unresolved decision and its boundary.

## Tickets

| Ticket | Type | Mode | Status | Depends On | Gate |
|---|---|---|---|---|---|
| [Decision title](tickets/WF-NNN-decision.md) | Grilling | HITL | **Open** | — | — |

## Blocking relationships

```text
Decision A ──→ Decision B ──→ Implementation handoff
```

## Frontier

[Decision title](tickets/WF-NNN-decision.md) is the one next grillable decision.

## Not yet specified (fog)

- Record bounded unknowns that may become decision tickets.

## Out of scope

- Record work that belongs after this planning handoff.
