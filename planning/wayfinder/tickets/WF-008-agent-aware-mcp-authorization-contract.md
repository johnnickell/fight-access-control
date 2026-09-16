# Define the Agent-aware MCP authorization contract

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Agent-aware MCP authorization and self-service profile tools](../agent-aware-mcp-authorization-map.md)
**Depends on:** —

## Question

What public, reusable contract lets AccessControl require direct Agent Permissions for MCP tools while preserving
Fight Common as policy-free protocol/CQRS infrastructure and making protected tools unavailable and uninvokable to
an unauthenticated or unauthorized Agent?

## Must decide

- The placement and namespace of `RequiresAgentPermission` metadata, its missing-metadata behavior, and the
  semantics when a tool declares multiple required Permissions.
- The decorator or service lifecycle that resolves the authenticated Agent principal and checks the requirement in
  both discovery and invocation paths.
- The fail-closed equivalence: missing authentication or a missing required managed Permission conceals a tool and
  makes direct invocation indistinguishable from an unknown tool.
- The lifecycle and cache behavior necessary to recheck authority before a later MCP request or protected
  interaction retry, including revocation and Permission changes before command-bus dispatch.

## Evidence and implementation gates

- Fight Common PR #157 confirms the policy-free protocol capability boundary, but explicitly defers tools and
  availability filtering. Common's later public tool metadata and neutral availability contracts are therefore an
  external implementation gate, not evidence already satisfied by WF-008.
- Existing AccessControl `CurrentAgentPrincipalProvider` resolves one authoritative, request-scoped HMAC Agent
  principal with current credential and Permission-assignment fencing.
- The conformance matrix below defines the executable proof required after Common publishes that tool surface.

## Resolution boundary

This ticket may settle only the public Agent-aware MCP authorization contract and its discovery/invocation
enforcement lifecycle. It must preserve consumer ownership of routes, authentication selection, API policy, TLS,
limits, origins, wiring, and exposed-tool selection; it must not prescribe OAuth claim mapping or treat OAuth scopes
as Agent Permissions.

The subsequent implementation handoff must separately specify `UpdateAgent`, its rename transition and
post-commit event, managed Permission definitions and stable IDs, self-profile output schemas, idempotency for an
unchanged name, and concrete safe-failure classifications.

## Resolution

`Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission` is repeatable
AccessControl-owned metadata attached at Common's public MCP tool declaration boundary; every declared Permission is
required. Whether the eventual PHP attribute target is the tool class or its `handle()` method follows Common's
published `McpTool` contract and is not guessed by WF-008. An Agent-protected composition rejects a registered tool
with no such metadata. A malformed or unexpectedly missing runtime mapping fails closed. Public tools use a separate
unprotected composition rather than an implicit missing-attribute exception.

Fight Common owns tool registration, resolved canonical tool names, `tools/list` filtering, `tools/call` selection,
and generic unknown-tool behavior. AccessControl implements Common's neutral availability boundary. That
request-scoped integration lazily resolves one current `AuthenticatedAgentPrincipal`, checks every Permission in an
immutable canonical-name-to-Permissions catalog, and returns only available or unavailable; Common never receives an
Agent, principal, Permission model, or authorization policy.

The catalog is derived from the same registered tool set, Common's resolved canonical metadata, and AccessControl's
attributes once per constructed integration instance. A framework may construct that instance on each request and a
consumer may compile static metadata per release, but availability checks do not repeat reflection after the instance
exists. AccessControl neither owns a filesystem cache nor independently reproduces Common's canonical naming rules.

Each MCP request receives a fresh `CurrentAgentPrincipalProvider` and authoritative persistence-backed Agent snapshot;
the snapshot is reused only inside that request. A later invocation or protected-interaction retry resolves authority
again. Only static tool metadata may cross request boundaries. Filtered authorization remains request-local, and
authorization-sensitive discovery uses `ttlMs: 0` and `cacheScope: private`.

Discovery removes unavailable tools before ordering and pagination. Invocation denies an unknown or unavailable tool
with the same public outcome before tool-specific validation, CQRS dispatch, progress, interaction-state disclosure,
or resume once a request reaches the availability integration. A consumer may still reject transport authentication
before MCP dispatch. This accepts one explicit snapshot tradeoff: authority revoked after the request-local principal
has been resolved does not cancel work already authorized in that request, but the next request observes the
revocation.

The early, not-yet-reviewed [Fight Common PR #157](https://github.com/johnnickell/fight-common/pull/157) confirms that
`McpCapabilityRegistry` owns protocol-method registration and explicitly defers tools and availability filtering. It
therefore supports the ownership boundary but is not a per-tool class-name or canonical-name map. Exact neutral
availability and resolved-tool-metadata PHP signatures remain an implementation dependency on Common's later tool
surface. This includes the exact attribute target supported by that declaration surface; it does not reopen the
AccessControl namespace, tool-local ownership, or authorization semantics settled here.

## Conformance matrix

| Scenario | Required observable outcome |
|---|---|
| Agent holds every declared Permission during `tools/list` | Tool is included; Common receives only the neutral availability result. |
| Agent is unresolved or lacks any declared Permission during `tools/list` | Tool is removed before ordering and pagination. |
| Protected tool has missing or malformed requirement metadata | Composition fails when possible; a runtime fallback is unavailable, never allowed. |
| Tool declares multiple requirements | Every Permission is required; each missing-Permission permutation is concealed. |
| Direct invocation names an unknown or unavailable tool | Public outcomes are indistinguishable; no tool validation, tool call, command, query, or diagnostic work occurs. |
| Authority changes between discovery and invocation | The invocation request resolves a fresh principal and denies before protected work. |
| Authority changes between protected interaction rounds | The retry request denies before restored-state disclosure, input validation, `resume()`, or bus dispatch. |
| Authority changes after principal resolution in one request | Already-authorized work may continue under that snapshot; the next request observes the change. |
| Discovery or availability is repeated | Only static requirement metadata may cross requests; Agent and filtered results remain request-local with `ttlMs: 0` and `cacheScope: private`. |

[EPIC-00005](../../epics/00005-EPIC.md) is the resulting implementation-planning handoff. Creating child TICKETs,
TASKs, implementation, delivery, or release effects requires separate authorization.
