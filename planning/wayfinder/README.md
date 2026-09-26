# Wayfinder Maps

Wayfinder maps chart an uncertain feature before it becomes an EPIC, TICKET, or implementation TASK. A map is an
index of linked decision tickets, not a second source of decisions. Start with an active map's **Frontier**; when
none is available, `/ask-matt` should offer `/wayfinder` to chart a new feature.

| Map | Status | Frontier | Handoff |
|---|---|---|---|
| [Enforce permission grant tiers for v0.4.0](permission-grant-tiers-v0-4-0-map.md) | Active | [Custom Permission delegation](tickets/WF-012-custom-permission-delegation.md) | — |
| [Agent-aware MCP authorization and self-service profile tools](agent-aware-mcp-authorization-map.md) | Active | Self-service profile contract (not yet opened) | [EPIC-00005](../epics/00005-EPIC.md) |
| [Recoverable credential delivery](recoverable-credential-delivery-map.md) | Closed | — | [TASK-00037](../tasks/00037-TASK.md) |
| [OpenAPI schema components for v0.2.0](openapi-schema-components-v0-2-0-map.md) | Closed | — | [TASK-00033](../tasks/00033-TASK.md) |
| [Agent HMAC authentication and direct authority](agent-hmac-authentication-map.md) | Closed | — | [TICKET-00002](../tickets/00002-TICKET.md) |

Use `_MAP_TEMPLATE.md` and `tickets/_WAYFINDER_TICKET_TEMPLATE.md` for new work. `research/` holds linked
evidence, never a parallel decision record. Archive only through `../../bin/archive-planning` after a map is Closed,
its decisions are Closed, its frontier is empty, and its implementation handoff is linked.
