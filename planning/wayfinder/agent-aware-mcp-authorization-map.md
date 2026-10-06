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
dependency-ordered Tool binding. Their TASK decomposition is complete; neither reopens this map.

## Notes

- AccessControl owns Agent identity, current authority, and reusable Agent-aware MCP availability and invocation
  integration. Consumers own routes, authentication selection, API policy, wiring, TLS, limits, origins, and
  exposed-tool selection.
- [WF-024](tickets/WF-024-self-service-agent-profile-contract.md) settles the minimal fresh self-profile read,
  separate managed `ADMIN_SAFE` read/update Permissions with one-time consumer-owned `Uuid::comb()` IDs, and a generic
  name-only `UpdateAgent` command with typed User/Agent initiator. Rename uses `AgentName`, is last-write-wins,
  preserves other Agent authority and publishes a fact only after a real committed change. WF-024's amended update
  acknowledgement is exactly `{agent_id, name}` from normalized command input after successful void dispatch,
  with matching JSON text and no changed/no-op distinction. No-op effects and failure classifications are unchanged.
  The self-service tools derive their target only from the authenticated principal; consumers own authorization for generic command entry points.
- [WF-023](tickets/WF-023-self-service-agent-target.md) settles self-service target selection: both profile tools
  derive their target Agent ID exclusively from the authenticated principal and accept no caller-supplied target
  Agent ID. A separate non-MCP administrator API remains outside this decision.
- The administrative `AgentView` is broader than the approved self-profile output. TASK-00069 now supplies the
  accepted name-only transition/writer and TASK-00070 the minimal profile query. TASK-00071 reuses them rather than
  treating credential or Permission replacement as rename. Credential/Permission operations remain outside these
  self-service Tools; their current APIs, not this map's original source baseline, govern other use cases.
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
The installed v1.3.0 API inspection clears the Common external hold. TASK-00070, TASK-00035 and TASK-00069 are now
done; TASK-00071 now has independent technical acceptance and behavioral QA PASS and is also done. Automatic
parent completion closes TICKET-00019 and EPIC-00011. The TASK owns authorized PR delivery; consumer qualification,
merge and release remain separate. The Board owns execution selection. No decision is reopened by completion.

## Not yet specified (fog)

None requiring another Wayfinder decision. WF-024 owns the safe output and bounded package evidence. Consumer
OAuth-to-Agent mapping is outside this package; scopes are not Agent Permissions.
[TASK-00035's verified v1.3.0 contracts](../tasks/00035-TASK.md#verified-fight-common-v130-contracts) now record
the installed release, exact signatures, method-level declaration and error/retry mechanics. That source inspection
satisfies the earlier external gate without reopening any decision or claiming implemented integration.

## Out of scope

- Creating a TASK, implementation code, consumer route, or public MCP endpoint without its separately authorized
  planning or delivery workflow.
- Credential issuance, rotation, revocation, Permission mutation, ownership changes, and raw-secret output through
  self-service MCP.
- Consumer authentication, authorization policy, OAuth client setup, TLS, origins, limits, persistence, runtime
  wiring, and exposed-tool selection.

## Resolution

[EPIC-00005](../epics/00005-EPIC.md) and [TICKET-00006](../tickets/00006-TICKET.md) retain the WF-008
integration boundary; TASK-00035 now records satisfaction of the external Common gate. John approved the distinct
[Agent Profile Tools and Name Management](../epics/00011-EPIC.md) destination in
[WF-024](tickets/WF-024-self-service-agent-profile-contract.md), including focused unit coverage of owned classes
and only useful targeted package integration/composition checks; no exhaustive duplicate suite. John confirmed the
EPIC boundary and approved [TICKET-00018](../tickets/00018-TICKET.md) for generic rename and
[TICKET-00019](../tickets/00019-TICKET.md) for self-service tools. TICKET-00018 has an approved single
[TASK-00069](../tasks/00069-TASK.md); TICKET-00019 has independently executable read/definition
[TASK-00070](../tasks/00070-TASK.md) and dependency-blocked Tool [TASK-00071](../tasks/00071-TASK.md). Both TICKET
plans are complete. No implementation, consumer migration, publication or deployment follows from these planning
decisions.
