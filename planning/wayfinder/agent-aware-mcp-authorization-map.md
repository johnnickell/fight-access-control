# Wayfinder Map: Agent-aware MCP authorization and self-service profile tools

**Label:** `wayfinder:map`
**Status:** Active

> This map is an **index, not a store**. Each material decision lives in exactly one linked decision ticket under
> `tickets/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Produce an implementation-ready handoff for reusable, Agent-aware MCP tool authorization in Fight AccessControl,
with two initial self-service tools: a safe read of the authenticated Agent profile and an update of that Agent's
own name. Fight Common supplies protocol and CQRS infrastructure plus tool metadata only; it does not receive an
Agent, principal, Permission, or authorization decision.

**Done** = every linked decision ticket is closed, the remaining fog is resolved or excluded, and the map links
to its resulting EPIC, TICKET, and/or implementation TASKs.

## Notes

- AccessControl owns Agent identity, current authority, and reusable Agent-aware MCP availability and invocation
  integration. Consumers own routes, authentication selection, API policy, wiring, TLS, limits, origins, and
  exposed-tool selection.
- The next grill retains, but has not accepted, candidate managed `ADMIN_SAFE` Permissions named
  `AGENT_PROFILE_READ` and `AGENT_PROFILE_UPDATE`. Managed reconciliation would not assign them automatically;
  stable identities remain undecided.
- The next grill must test the provisional safety hypothesis that MCP self-service tools derive their target Agent ID
  exclusively from the authenticated principal and accept no target Agent ID, while a non-MCP administrator API may
  use a generic target-Agent update command.
- The existing secret-free `AgentView`, a generic Agent rename use case, aggregate-owned transition, atomic
  persistence, post-commit event, and acknowledgement without a compensating read are candidate building blocks for
  the later profile handoff. They are evidence and proposals, not decisions made by WF-008.
- Existing Permission-mutation commands require a `UserId` administrator, and credential lifecycle services may
  return raw secrets. Neither is eligible for self-service MCP.
- The prior [Agent HMAC map](agent-hmac-authentication-map.md) established the request-scoped authenticated Agent
  principal. This map extends that seam without revising its consumer-owned transport and authorization boundary.

## Decisions so far

1. **The public Agent-aware MCP authorization contract is settled.** AccessControl owns repeatable conjunctive
   `RequiresAgentPermission` metadata, a static catalog keyed by Common's resolved canonical tool names, and a
   request-scoped neutral availability implementation. Common owns discovery and invocation mechanics without
   receiving Agent policy objects. Missing metadata rejects protected composition; unavailable and unknown tools are
   publicly equivalent before validation or dispatch. [WF-008](tickets/WF-008-agent-aware-mcp-authorization-contract.md)
   records the full decision and [EPIC-00005](../epics/00005-EPIC.md) is its implementation-planning handoff.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-008 | [Define the Agent-aware MCP authorization contract](tickets/WF-008-agent-aware-mcp-authorization-contract.md) | Grilling | HITL | **Closed** | — |

## Blocking relationships

```text
Agent-aware MCP authorization contract ──→ MCP self-service profile implementation handoff
```

## Frontier

The authorization frontier is closed and handed off through [EPIC-00005](../epics/00005-EPIC.md). The map remains
active because the self-service profile tools still need a separately opened decision record covering their behavior,
schemas, Permission identities, and update semantics before they can receive an implementation handoff.

## Not yet specified (fog)

- Whether an unchanged `UpdateAgent` name is idempotent and whether it emits an event.
- The exact safe MCP output schemas and text presentations.
- How consumer OAuth claims establish an authoritative Agent. Fight Common may validate or return claims, but OAuth
  scopes are not Agent Permissions by default.
- Fight Common planning now names the intended `McpTool`, method-level `McpToolInfo`, and request-scoped neutral
  availability concepts, but no compatible published release is installed here. Exact public signatures and the
  supported attribute target remain an external implementation dependency; they do not reopen the settled
  authorization behavior or justify guessing before Common publishes that surface.

## Out of scope

- Creating a TASK, implementation code, consumer route, or public MCP endpoint without its separately authorized
  planning or delivery workflow.
- Credential issuance, rotation, revocation, Permission mutation, ownership changes, and raw-secret output through
  self-service MCP.
- Consumer authentication, authorization policy, OAuth client setup, TLS, origins, limits, persistence, runtime
  wiring, and exposed-tool selection.

## Resolution

[EPIC-00005](../epics/00005-EPIC.md) captures the closed WF-008 authorization contract, and
[TICKET-00006](../tickets/00006-TICKET.md) owns its cohesive requirements while awaiting Fight Common's published
Tool surface. The broader map remains active for the two self-service profile tools; no TASK or implementation
change has been created.
