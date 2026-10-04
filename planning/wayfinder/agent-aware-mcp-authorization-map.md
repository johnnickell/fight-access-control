# Wayfinder Map: Agent-aware MCP authorization and self-service profile tools

**Label:** `wayfinder:map`
**Status:** Closed

> This map is an **index, not a store**. Each material decision lives in exactly one linked decision ticket under
> `tickets/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Produce an implementation-ready handoff for reusable, Agent-aware MCP tool authorization in Fight AccessControl,
with two initial self-service tools: a safe read of the authenticated Agent profile and an update of that Agent's
own name. Fight Common supplies protocol and CQRS infrastructure plus tool metadata only; it does not receive an
Agent, principal, Permission, or authorization decision.

**Done** = all three decision tickets are closed. [EPIC-00005](../epics/00005-EPIC.md) owns the existing
authorization handoff, and [EPIC-00011](../epics/00011-EPIC.md) owns the distinct approved profile destination.
[TICKET-00018](../tickets/00018-TICKET.md) and [TICKET-00019](../tickets/00019-TICKET.md) now record its
approved requirement decomposition. [TASK-00069](../tasks/00069-TASK.md) owns generic rename, while
[TASK-00070](../tasks/00070-TASK.md) and [TASK-00071](../tasks/00071-TASK.md) own minimal profile reads and
externally gated Tool binding. Their TASK decomposition is complete; neither reopens this map.

## Notes

- AccessControl owns Agent identity, current authority, and reusable Agent-aware MCP availability and invocation
  integration. Consumers own routes, authentication selection, API policy, wiring, TLS, limits, origins, and
  exposed-tool selection.
- [WF-024](tickets/WF-024-self-service-agent-profile-contract.md) settles the minimal fresh self-profile read,
  separate managed `ADMIN_SAFE` read/update Permissions with one-time consumer-owned `Uuid::comb()` IDs, and a generic
  name-only `UpdateAgent` command with typed User/Agent initiator. Rename uses `AgentName`, is last-write-wins,
  preserves other Agent authority and publishes a fact only after a real committed change. No-op retries, safe
  acknowledgements and failure classifications are specified in WF-024. The self-service tools derive their target
  only from the authenticated principal; consumers own authorization for generic command entry points.
- [WF-023](tickets/WF-023-self-service-agent-target.md) settles self-service target selection: both profile tools
  derive their target Agent ID exclusively from the authenticated principal and accept no caller-supplied target
  Agent ID. A separate non-MCP administrator API remains outside this decision.
- The existing administrative `AgentView` is broader than the approved self-profile output. `Agent` currently has
  no rename transition, and `AgentRepository` has no name-only writer; the future EPIC must design them without
  treating credential or Permission replacement as a rename path.
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
2. **Self-service target selection is settled.** Both profile tools target only the authenticated Agent, with no
   caller-supplied target Agent ID. John confirmed this boundary; [WF-023](tickets/WF-023-self-service-agent-target.md)
   owns the decision. Profile implementation must preserve it.
3. **The profile contract is settled.** [WF-024](tickets/WF-024-self-service-agent-profile-contract.md) records John's
   read/update, Permission, last-write-wins, provenance, failure and lean evidence decisions. Its distinct approved
   destination is [EPIC-00011](../epics/00011-EPIC.md); no profile implementation is authorized by map closure.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-008 | [Define the Agent-aware MCP authorization contract](tickets/WF-008-agent-aware-mcp-authorization-contract.md) | Grilling | HITL | **Closed** | — |
| WF-023 | [Bind self-service profile tools to the authenticated Agent](tickets/WF-023-self-service-agent-target.md) | Grilling | HITL | **Closed** | WF-008 |
| WF-024 | [Define the self-service Agent profile contract](tickets/WF-024-self-service-agent-profile-contract.md) | Grilling | HITL | **Closed** | WF-008, WF-023 |

## Blocking relationships

```text
Agent-aware MCP authorization contract ──→ Self-service target selection ──→ Remaining profile contract ──→ Profile implementation handoff
```

## Frontier

None. WF-008, WF-023 and WF-024 are closed. The authorization contract is handed off through
[EPIC-00005](../epics/00005-EPIC.md), and the profile contract through [EPIC-00011](../epics/00011-EPIC.md).
The profile EPIC's TICKET-00018/00019 requirement split and TASK plans (TASK-00069/00070/00071) are approved.
TASK-00071 still needs a compatible published and installed Common Tool API and its three TASK dependencies;
planning completion does not make it executable. No decision is reopened by that planning work.

## Not yet specified (fog)

None requiring another Wayfinder decision. WF-024 owns the safe output and bounded package evidence. Consumer
OAuth-to-Agent mapping is outside this package; scopes are not Agent Permissions. Fight Common v1.2.0 is not a
compatible published Tool surface. The exact Tool signatures, supported attribute target and wire-error mechanics
must be inspected against a future installable release before implementation, not guessed in this map.

## Out of scope

- Creating a TASK, implementation code, consumer route, or public MCP endpoint without its separately authorized
  planning or delivery workflow.
- Credential issuance, rotation, revocation, Permission mutation, ownership changes, and raw-secret output through
  self-service MCP.
- Consumer authentication, authorization policy, OAuth client setup, TLS, origins, limits, persistence, runtime
  wiring, and exposed-tool selection.

## Resolution

[EPIC-00005](../epics/00005-EPIC.md) and [TICKET-00006](../tickets/00006-TICKET.md) retain the WF-008
integration boundary and external Common gate. John approved the distinct
[Agent Profile Tools and Name Management](../epics/00011-EPIC.md) destination in
[WF-024](tickets/WF-024-self-service-agent-profile-contract.md), including focused unit coverage of owned classes
and only useful targeted package integration/composition checks; no exhaustive duplicate suite. John confirmed the
EPIC boundary and approved [TICKET-00018](../tickets/00018-TICKET.md) for generic rename and
[TICKET-00019](../tickets/00019-TICKET.md) for self-service tools. TICKET-00018 has an approved single
[TASK-00069](../tasks/00069-TASK.md); TICKET-00019 has independently executable read/definition
[TASK-00070](../tasks/00070-TASK.md) and externally gated Tool [TASK-00071](../tasks/00071-TASK.md). Both TICKET
plans are complete. No implementation, consumer migration, publication or deployment follows from these planning
decisions.
