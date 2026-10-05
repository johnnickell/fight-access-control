---
id: TICKET-00019
epic: EPIC-00011
title: Read and update the authenticated Agent profile through MCP tools
status: needs-info
---

# Read and update the authenticated Agent profile through MCP tools

## Problem and Outcome

An authenticated Agent needs a safe way to see its own name and to rename itself, without disclosing the broader
administrative Agent view or allowing a caller to select another Agent. Supply two protected, package-owned MCP tools
bound to the Agent-aware authorization contract in [TICKET-00006](00006-TICKET.md). Reuse the generic name-only
update from [TICKET-00018](00018-TICKET.md); do not implement a second rename path. This TICKET is `needs-info`
for Tool integration until a compatible published and installed Fight Common Tool surface is inspected. This does
not block planning of independent profile behavior or TICKET-00018's generic command.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Observable outcome and side effects |
| --- | --- | --- | --- | --- |
| An authenticated Agent with `AGENT_PROFILE_READ` requests its own profile | N/A | Fresh minimal profile lookup of the Agent ID from the current authenticated principal | N/A | Return only structured `{agent_id, name}` through Common's standard output with matching derived JSON text, not a separately authored sentence; no write, transaction commit or event. Do not expose the broader `AgentView`. |
| An authenticated Agent with `AGENT_PROFILE_UPDATE` supplies a new name | Dispatch generic `UpdateAgent` with the principal's Agent as both target and typed initiator | N/A; no compensating lookup | The command's name-changed fact on a real committed rename only | After successful synchronous void dispatch, return exactly `{agent_id, name}` from the target and normalized command input, with Common's matching JSON text. No changed/no-op distinction or compensating read. No-op writes nothing and emits no success fact. |
| An unresolved or under-permissioned Agent lists or invokes a protected tool | N/A; never dispatch before authorization | N/A | N/A | Apply TICKET-00006's request-local, conjunctive metadata checks and unknown/unavailable equivalence before tool input validation, read or command dispatch. Another MCP request observes changed authority. |
| A valid caller encounters missing/revoked current target, bad name or operation failure | No write for invalid input or unavailable target; the generic update owns its transaction | Fresh read when requested, no compensating post-update read | No success fact on rejection; publication failure after commit is possible | Missing/revoked current target is generically unavailable; invalid name is validation failure. Sanitize tool errors and never assert rollback after post-commit publication failure. Wire-level representations follow published Common signatures. |

## Permissions and Integration

- Supply two distinct managed `ADMIN_SAFE` definitions named `AGENT_PROFILE_READ` and `AGENT_PROFILE_UPDATE`, with
  no automatic assignment. Each consumer generates **two IDs once** with Fight Common `Uuid::comb()`, fixes them in
  its seed/migration and never regenerates on reconciliation or startup. No package-owned fixed UUIDs, grants,
  consumer migration or deployment are implied.
- Each tool derives its target Agent ID exclusively from the authenticated Agent principal and accepts **no**
  caller-supplied target ID. The updater's name input is the only caller-supplied update field. Both tools use the
  AccessControl-owned requirement metadata and neutral availability integration of TICKET-00006; they do not pass
  Agent or Permission objects to Fight Common or make OAuth scopes into Agent Permissions.
- The read returns exactly ID and name from an authoritative fresh lookup, not an administrative projection with
  credential/Permission state. It has no Domain mutation, Command or success Event; the updater reuses TICKET-00018's
  command/fact rather than publishing a tool-specific name-change fact. Its acknowledgement uses only validated
  command input after successful void dispatch, not a handler/bus result or latest-state lookup. A thrown dispatch
  failure produces no success acknowledgement. An update error must not include secrets,
  internal storage diagnostics or a false rollback claim.
- Unknown/unavailable Tool concealment occurs before validation or protected work. The generic command's typed
  initiator and target never grant caller authority: the protected MCP composition checks its managed Permission,
  while consumer operators guard any other generic command-dispatch entry point. Consumer transport may reject
  authentication before this MCP integration.

## Acceptance Evidence

- [ ] Focused unit coverage of each new class proves minimal fresh read, principal-only target and initiator,
      independent read/update Permission requirements, minimal structured outputs with Common's matching JSON text and invalid/missing/no-op/real
      rename outcomes. No caller-supplied target ID is accepted.
- [ ] Only useful targeted package composition checks bind these tools to TICKET-00006's authorization boundary
      and the shared TICKET-00018 rename transition, including denial before work, safe failure presentation and
      post-commit publication uncertainty. Reuse TICKET-00006's discovery/invocation/retry conformance rather than
      copying its full scenario matrix; do not add an exhaustive suite.
- [ ] Confirm actual installed Fight Common canonical names, declaration target, Tool/availability signatures and
      error mechanics before implementing the MCP declarations. Document consumer-managed stable-ID seeding and
      entry-point authorization obligations without claiming to run a consumer migration or real MCP process.
- [ ] Focused checks, `./bin/planning-check` and canonical `./bin/build` pass with exact production-statement
      coverage when implementation is complete. Package-controlled tests are not consumer database, scanner,
      transport or runtime qualification.

## Dependencies and Exclusions

[WF-023](../wayfinder/tickets/WF-023-self-service-agent-target.md) and
[WF-024](../wayfinder/tickets/WF-024-self-service-agent-profile-contract.md) own targeting and safe profile
behavior. [WF-008](../wayfinder/tickets/WF-008-agent-aware-mcp-authorization-contract.md) and
[TICKET-00006](00006-TICKET.md) own reusable authorization, concealment and request lifecycle;
[TICKET-00018](00018-TICKET.md) owns generic rename and its fact. The installed Fight Common v1.2.0 lacks the
compatible Tool surface. As in [TASK-00035](../tasks/00035-TASK.md), wait for a published/installable version and
inspect its actual signatures before Tool integration; no PHP attribute target or wire error is guessed here.

Consumer routes, authentication selection, OAuth claims, TLS, limits, policy and grants, persistence adapters,
actual writer-race qualification, scanning, MCP runtime composition and exposed-tool selection are outside this
package TICKET. No administrator API, credential or Permission management tool, raw-secret output, production
Adapter layer, release or deployment is created.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 70 | [TASK-00070](../tasks/00070-TASK.md) | Provide minimal Agent profile reads and managed Permission definitions | ready-for-agent |
| 71 | [TASK-00071](../tasks/00071-TASK.md) | Bind protected self-service Agent profile tools | needs-info |
## Progress

John subsequently simplified the update acknowledgement to `{agent_id, name}`, derived from normalized command
input after successful void dispatch. Common's derived JSON text replaces the update's changed/no-op sentences.
John then removed the read tool's separately authored sentence too; fresh lookup, Permission enforcement and
minimal safe fields remain unchanged.

John approved this as the second EPIC-00011 requirement area and subsequently approved two implementation slices:
[TASK-00070](../tasks/00070-TASK.md) delivers the independently executable minimal read/managed definitions;
[TASK-00071](../tasks/00071-TASK.md) binds the tools after TASK-00070, TASK-00069, TASK-00035 and a compatible
published/installed Common Tool API. TASK decomposition is complete; Tool integration remains `needs-info`.
Consumer adoption and implementation require separate authorization and checkout selection.
