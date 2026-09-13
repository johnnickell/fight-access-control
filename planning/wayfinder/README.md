# Wayfinder Maps

Wayfinder maps chart an uncertain feature before it becomes an epic, PRD, or implementation ticket. A map is an
index of linked decision tickets, not a second source of decisions. Start with an active map's **Frontier**; when
none is available, `/ask-matt` should offer `/wayfinder` to chart a new feature.

| Map | Status | Frontier | Handoff |
|---|---|---|---|
| [OpenAPI schema components for v0.2.0](openapi-schema-components-v0-2-0-map.md) | Closed | [T-00033](../tickets/00033-TICKET.md) | — |
| [Agent HMAC authentication and direct authority](agent-hmac-authentication-map.md) | Closed | — | [PRD-00002](../specs/00002-PRD.md) |

Use `_MAP_TEMPLATE.md` and `tickets/_WAYFINDER_TICKET_TEMPLATE.md` for new work. `research/` holds linked
evidence, never a parallel decision record. Archive only through `../bin/archive-planning` after a map is Closed,
its decisions are Closed, its frontier is empty, and its implementation handoff is linked.
