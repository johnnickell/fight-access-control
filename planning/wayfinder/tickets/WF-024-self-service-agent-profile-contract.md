# Define the self-service Agent profile contract

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
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

## Resolution

Open. John requested opening the remaining profile-contract decision. No remaining contract choice has been resolved.
