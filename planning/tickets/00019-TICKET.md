---
id: TICKET-00019
epic: EPIC-00011
title: Read and update the authenticated Agent profile through MCP tools
status: ready-for-agent
---

# Read and update the authenticated Agent profile through MCP tools

## Problem and Outcome

An authenticated Agent needs a safe way to see its own name and to rename itself, without disclosing the broader
administrative Agent view or allowing a caller to select another Agent. Supply two protected, package-owned MCP tools
bound to the Agent-aware authorization contract in [TICKET-00006](00006-TICKET.md). Reuse the generic name-only
update from [TICKET-00018](00018-TICKET.md); do not implement a second rename path. Installed Fight Common v1.3.0
now satisfies the external Tool API gate; this TICKET is `ready-for-agent`. TASK-00070 and TASK-00035 are done;
TASK-00071 is executable with all recorded dependency edges satisfied.

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
[TICKET-00018](00018-TICKET.md) owns generic rename and its fact.
[TASK-00035's verified v1.3.0 contracts](../tasks/00035-TASK.md#verified-fight-common-v130-contracts) supply the
installed-version evidence, method-level declaration, canonical registry, boolean availability and Common error
mechanics. [TASK-00071](../tasks/00071-TASK.md#verified-compatibility-and-dependencies) records the profile binding
to `McpToolOutput::structured()` and safe failure mapping. Source compatibility does not satisfy the still-required
AccessControl implementation/composition evidence; recheck actual resolved and accepted contracts at intake.

Consumer routes, authentication selection, OAuth claims, TLS, limits, policy and grants, persistence adapters,
actual writer-race qualification, scanning, MCP runtime composition and exposed-tool selection are outside this
package TICKET. No administrator API, credential or Permission management tool, raw-secret output, production
Adapter layer, release or deployment is created.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 70 | [TASK-00070](../tasks/00070-TASK.md) | Provide minimal Agent profile reads and managed Permission definitions | done |
| 71 | [TASK-00071](../tasks/00071-TASK.md) | Bind protected self-service Agent profile tools | ready-for-agent |
## Progress

John subsequently simplified the update acknowledgement to `{agent_id, name}`, derived from normalized command
input after successful void dispatch. Common's derived JSON text replaces the update's changed/no-op sentences.
John then removed the read tool's separately authored sentence too; fresh lookup, Permission enforcement and
minimal safe fields remain unchanged.

John approved this as the second EPIC-00011 requirement area and subsequently approved two implementation slices:
[TASK-00070](../tasks/00070-TASK.md) delivers the independently executable minimal read/managed definitions;
[TASK-00071](../tasks/00071-TASK.md) binds the tools after TASK-00070, TASK-00069 and TASK-00035. TASK decomposition
is complete. The subsequent v1.3.0 source inspection clears the external hold; TASK-00071 is ready but waiting on
TASK-00070, while TASK-00069 and TASK-00035 are done. Consumer adoption and further implementation still require
separate authorization and checkout selection.

John invoked work for TASK-00070 in the main checkout. Its minimal fresh profile query and managed Permission
factory are implemented with [consumer composition guidance](../../docs/agent-profile.md), pending independent
review and behavioral QA. Builder checks pass 32 focused tests / 213 assertions and the complete local gate passes
2080 tests / 40412 assertions, exact 7000/7000 owned statements with no final warnings/skips. The TASK retains
resolved-tool drift and interrupted-attempt chronology. TASK-00071 still owns actual self-service Tool binding;
this checkpoint does not complete the TICKET or qualify consumer persistence, seeding or runtime.

Independent review of `d75db98` requested F1's bounded test-quality correction, not a production behavior repair.
The revision removes constructor/private-property layout assertions while retaining behavior and forbidden-effect
checks. Fresh focused/full gates pass 31 tests / 210 assertions and 2079 tests / 40409 assertions, exact 7000/7000
owned statements without warnings/skips. At that builder checkpoint independent re-review and behavioral QA were
pending. Independent re-review subsequently accepts `915323b` against unchanged `develop` `2ee2597`, all C1–C4
passing, F1 resolved and no remaining findings. Independent QA passes six groups, 27 executable cases / 116 checks,
eight instruction walkthroughs and 31 focused tests / 210 assertions. TASK-00070 is now done for accepted package
implementation/local verification; John authorized landing and its TASK owns final publication evidence. Automatic
parent completion leaves this TICKET and EPIC-00011 ready-for-agent for unfinished TASK-00071, now executable.
Actual Tool binding and consumer qualification remain separate; no parent completion, merge or release is claimed.
