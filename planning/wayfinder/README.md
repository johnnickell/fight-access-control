# Wayfinder Maps

Wayfinder maps chart an uncertain feature before it becomes an EPIC, TICKET, or implementation TASK. A map is an
index of linked decision tickets, not a second source of decisions. Start with an active map's **Frontier**; when
none is available, `/ask-matt` should offer `/wayfinder` to chart a new feature.

| Map | Status | Frontier | Handoff |
|---|---|---|---|
| [Consolidated AccessControl correction batch](access-control-correction-batch-map.md) | Closed | — | WF-029–031 and all planning phases accepted; TICKET-00001 → [TASK-00077](../tasks/00077-TASK.md), [TASK-00078](../tasks/00078-TASK.md), [TASK-00079](../tasks/00079-TASK.md); TICKET-00024 → [TASK-00080](../tasks/00080-TASK.md); TASK-00077–00080 complete and integrated, including merged PR #132; no remaining batch planning/implementation handoff. See the [current Roadmap handoff](../ROADMAP.md#current-planning-handoff--correction-batch-integrated). |
| [Optional user self-registration for v1.0.0](self-registration-v1-map.md) | Closed | — | [EPIC-00013](../epics/00013-EPIC.md) → [TICKET-00021](../tickets/00021-TICKET.md), [TICKET-00022](../tickets/00022-TICKET.md), [TICKET-00023](../tickets/00023-TICKET.md); [TASK-00073](../tasks/00073-TASK.md) → [TASK-00074](../tasks/00074-TASK.md) → [TASK-00075](../tasks/00075-TASK.md); all planning phases and implementation complete; published in v0.7.0. Consumer enablement/qualification remains separate. |
| [Human credential-delivery expiry cleanup](human-credential-delivery-expiry-map.md) | Closed | — | [EPIC-00012](../epics/00012-EPIC.md) → [TICKET-00020](../tickets/00020-TICKET.md) → [TASK-00072 — Discover and clean expired human credential work](../tasks/00072-TASK.md); decomposition and implementation complete; published in v0.5.0. Consumer qualification remains separate. |
| [Permission-based feature flags](permission-based-feature-flags-map.md) | Closed | — | [EPIC-00010 — Permission-Based Feature Flags](../epics/00010-EPIC.md) |
| [Enforce permission grant tiers for v0.4.0](permission-grant-tiers-v0-4-0-map.md) | Closed | — | [Enforce Permission Grant Tiers](../epics/00008-EPIC.md) |
| [Agent-aware MCP authorization and self-service profile tools](agent-aware-mcp-authorization-map.md) | Closed | — | [EPIC-00005](../epics/00005-EPIC.md); [EPIC-00011 — Agent Profile Tools and Name Management](../epics/00011-EPIC.md) |
| [Recoverable credential delivery](recoverable-credential-delivery-map.md) | Closed | — | [TASK-00037](../tasks/00037-TASK.md) |
| [OpenAPI schema components for v0.2.0](openapi-schema-components-v0-2-0-map.md) | Closed | — | [TASK-00033](../tasks/00033-TASK.md) |
| [Agent HMAC authentication and direct authority](agent-hmac-authentication-map.md) | Closed | — | [TICKET-00002](../tickets/00002-TICKET.md) |

Use `_MAP_TEMPLATE.md` and `tickets/_WAYFINDER_TICKET_TEMPLATE.md` for new work. `research/` holds linked
evidence, never a parallel decision record. Archive only through `../../bin/archive-planning` after a map is Closed,
its decisions are Closed, its frontier is empty, and its implementation handoff is linked.
