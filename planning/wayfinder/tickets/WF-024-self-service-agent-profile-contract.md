# Define the self-service Agent profile contract

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Agent-aware MCP authorization and self-service profile tools](../agent-aware-mcp-authorization-map.md)
**Depends on:** [WF-008](WF-008-agent-aware-mcp-authorization-contract.md), [WF-023](WF-023-self-service-agent-target.md)

## Question

What remaining public behavior and safe output contract should the two MCP self-service tools provide for reading
an authenticated Agent's profile and updating that Agent's own name?

## Must decide

- The profile fields exposed by the read tool and the update acknowledgement, including structured output and text
  presentation. Decide whether the existing secret-free `AgentView` is suitable or exposes more than self-service needs.
- The managed Permission names and stable identities for each tool. `AGENT_PROFILE_READ` and
  `AGENT_PROFILE_UPDATE`, classified `ADMIN_SAFE` without automatic assignment, remain candidates, not accepted choices.
- The name-update use case and validation, unchanged-name idempotency, concurrency/retry expectations, persistence
  effects, and post-commit event behavior. `UpdateAgent`, an aggregate-owned rename transition and acknowledgement
  without a compensating read are proposals to assess, not approved APIs.
- Safe failure classifications and observable outcomes for invalid names, missing or changed target state, persistence
  failure and post-commit publication failure, while preserving WF-008's unavailable/unknown-tool concealment.
- The package-owned versus consumer-owned integration and acceptance evidence needed for a profile implementation
  handoff, without designing a separate administrator API or guessing unpublished Common signatures.

## Required evidence

- Carry forward the closed [WF-008 authorization contract](WF-008-agent-aware-mcp-authorization-contract.md) and
  [WF-023 target-selection decision](WF-023-self-service-agent-target.md). Both tools derive their target exclusively
  from the authenticated principal; neither accepts a caller-supplied target Agent ID.
- Inspect current `Agent`, `AgentName`, `AgentView`, authenticated-principal/provider and repository contracts before
  recommending reuse, rename behavior, output fields or atomicity/concurrency rules. Existing administrative reads
  and Permission-mutation commands do not by themselves establish a self-service contract.
- Record explicit human decisions for the remaining choices, separating accepted behavior from candidate PHP APIs,
  Permission definitions and schemas. Opening this ticket accepts none of those candidates.
- Specify scenario-level acceptance outcomes for profile read, real rename, unchanged-name retry, invalid input,
  concurrent state changes and safe failures. Reuse WF-008's discovery/invocation/retry authorization evidence rather
  than reopening its request-local snapshot boundary.
- Keep the compatible published/installable Fight Common Tool surface as an external implementation gate, as
  recorded in [TASK-00035](../../tasks/00035-TASK.md). Exact declaration targets and protocol integration signatures
  require inspection of that release; planning is not proof that it exists or works.

## Resolution boundary

This ticket may settle only the remaining behavior, Permission identities, safe schemas and update semantics for
the two self-service profile tools, and identify their next planning handoff. WF-008's authorization ownership,
conjunctive checks, concealment and request lifecycle are settled. WF-023's authenticated-only target is settled.

Consumer routes, transport authentication and OAuth claim mapping, TLS, persistence adapters, runtime wiring and
exposed-tool selection remain consumer-owned. Credential issuance, rotation, revocation, Permission mutation,
ownership changes, raw-secret output and a separate administrator API are excluded. No credential-operation
publication exception is implicitly extended to name updates.

Opening this decision does not create an EPIC, requirement TICKET or implementation TASK, authorize implementation,
or qualify a consumer. Any resulting planning handoff requires its own approval.

## Settled decisions

- John agreed that the self-service read performs a fresh lookup of the authenticated Agent and returns only `agent_id` and `name`, with human-readable text such as `Agent: {name} ({id})`. The broader administrative `AgentView` (credential identity/revisions and Permission details) is not the self-service result.
- John approved two separate managed `ADMIN_SAFE` Permissions named `AGENT_PROFILE_READ` and `AGENT_PROFILE_UPDATE`, without automatic assignment. Consumers generate their two IDs once with Fight Common `Uuid::comb()` for seeding/migration and retain the values as stable managed-policy identities; reconciliation and application startup must not regenerate them. Actual consumer migration, grants and deployment remain separate. No package-owned fixed UUIDs are required.
- John chose last-write-wins for name changes: the update does not require the previous name or a new name revision from the profile read. The name-only write must not overwrite credential or Permission authority, or resurrect a revoked Agent; the safe failure contract is specified below.
- John approved reusing `AgentName::fromString()` for rename input: trim surrounding whitespace, reject the empty result or a name longer than 120 characters, and add no name-uniqueness rule. The generic command requires the current target to be `ACTIVE`, while the self-service tool binds that target to the authenticated Agent. Missing or revoked targets reject without changing credentials or Permissions.
- John approved normalized unchanged-name retry as a successful no-op: no persistence write, `updatedAt` change, or success event. A real rename commits atomically and publishes a name-changed fact only after commit; event publication is not guaranteed by the transaction.
- John approved a successful update acknowledgement with structured `{agent_id, name, changed}` using the committed normalized name and no compensating read. Text is `Agent name updated to {name} ({id})` for a real rename and `Agent name already {name} ({id})` for a no-op. No credential or Permission details are exposed.
- John approved safe failures: invalid name is validation failure with no write/event; missing or revoked current Agent after authentication is generically unavailable with no write; storage or pre-commit failure rolls back and exposes no internal error details through the tool; post-commit event-publication failure reports failure without implying rollback, since the name may already have changed. A later request may read or retry; neither event delivery nor successful retry outcome is promised. WF-008's unknown/unavailable concealment remains before tool work; ordinary command failure-event/rethrow behavior remains in force, not the credential-operation exception. Exact MCP wire errors await Common's published Tool surface.
- John chose a generic package `UpdateAgent` command for the name-only operation rather than a self-service-only command. A consumer may authorize it for administration or self-service at its entry point. The self-service MCP tool still derives its target Agent ID exclusively from its authenticated principal and accepts no caller-supplied target ID; the generic command does not itself grant either caller permission or another Agent's identity. No package administrator endpoint or wider Agent-field update is implied.
- John approved typed initiating User or Agent identity as provenance in `UpdateAgent` and its name-changed event. The MCP tool uses its authenticated Agent as initiator and target; an administrative caller uses its authenticated initiator and chosen target. Supplying an actor ID never grants authority: consumers protect every command-dispatch entry point, including direct bus calls.

## Resolution

**Closed.** John approved a separate EPIC destination, **Agent Profile Tools and Name Management**, for the generic
name-only `UpdateAgent` command and the two MCP self-service tools. After separate grill confirmation, that destination
is [EPIC-00011](../../epics/00011-EPIC.md). The approved behaviors above are its handoff;
[EPIC-00005](../../epics/00005-EPIC.md) continues to own only WF-008's authorization integration. The self-service
tools must use that authorization boundary and WF-023's authenticated-only target selection, not invent a second
Tool policy or allow a caller-supplied target. Consumers may independently authorize the generic command for
administration; no administrator endpoint is created here. TICKET/TASK decomposition remains separately authorized;
this decision itself creates none of them.

### Minimal acceptance evidence for the future EPIC

- Unit-test each new class's meaningful behavior under the project's exact production-statement coverage gate,
  including the fresh minimal read, name validation, active-state guard, real/unchanged rename effects and safe
  acknowledgements. Exercise typed initiator provenance and the self-service tool's principal-only target; no
  caller-controlled Agent ID is accepted by those tools.
- Use a small number of focused package composition/integration tests **where unit tests cannot establish the
  boundary**: generic command and self-service tool reaching the same name-only transition, last committed rename
  winning without clobbering credential/Permission updates or restoring revoked authority, and commit-before-event
  with rollback versus post-commit publication failure. Cover safe errors without duplicating every WF-008
  discovery/invocation/retry scenario; reuse its accepted conformance evidence and test only the new tool binding.
- Consumer persistence race qualification, managed-Permission seed/migration, authorization on every real entry
  point, scanning, runtime composition and actual MCP process behavior remain separate. The package's
  `./bin/planning-check` and full `./bin/build` gate remain required for implementation; modeled package checks
  cannot be reported as consumer qualification. Do not add an exhaustive cross-product suite or tests of planning
  prose merely to restate the same contract.

The installed Fight Common v1.2.0 does not provide the compatible published Tool API. Like
[TASK-00035](../../tasks/00035-TASK.md), tool integration must wait for an installable release and inspection of its
actual signatures; this does not reopen the settled profile behavior. Consumer OAuth claim mapping, transport and
wire-error details remain outside this package decision.
