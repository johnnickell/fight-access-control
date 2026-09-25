---
id: TICKET-00006
epic: EPIC-00005
title: Authorize Agent-Protected MCP Tools
status: needs-info
---

# Authorize Agent-Protected MCP Tools

## Problem and Outcome

Consumers need a reusable way to expose an MCP Tool only when the current authenticated Agent holds every direct
Permission required by that Tool. Reimplementing that check in each consumer would let discovery, direct invocation,
and protected-interaction retry drift apart, while passing an Agent or policy object into Fight Common would move
AccessControl knowledge into policy-free protocol infrastructure.

Provide AccessControl-owned, repeatable Permission metadata and one request-scoped implementation of Fight Common's
neutral Tool-availability boundary. An Agent-protected composition derives an immutable requirement catalog from the
same registered Tools and canonical names that Common uses. It resolves one authoritative Agent principal snapshot per
request, returns only available or unavailable to Common, and applies the same conjunctive decision before discovery,
direct invocation, and interaction retry can perform protected work.

This TICKET owns the complete Agent-aware authorization integration. It is `needs-info` until Fight Common publishes
and this package installs the compatible Tool metadata, canonical-name, availability, invocation, and protected-
interaction contracts. Fight Common's current planning names the intended `McpTool`, method-level `McpToolInfo`, and
request-scoped neutral availability concepts, but the installed Fight Common v1.2.0 exposes none of them. Exact PHP
signatures and the compatible attribute target must be resolved from the published API rather than guessed here.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A consumer composes an Agent-protected MCP Tool registry | N/A | N/A | N/A | Read each registered Tool's AccessControl requirement metadata once, pair it with Common's resolved canonical name, and build an immutable catalog. Reject missing or malformed metadata and duplicate or drifting canonical identity before serving requests. |
| An authenticated Agent requests `tools/list` | N/A | N/A | N/A | Resolve the current Agent through one request-scoped `CurrentAgentPrincipalProvider`, including its existing nonce-consumption and authority-fencing behavior. Include a Tool only when the immutable snapshot contains every declared Permission; Common filters before deterministic ordering and pagination. |
| An authenticated Agent directly invokes a Tool | N/A | N/A | N/A | Apply the same availability decision before Tool-specific validation, `handle()`, command/query dispatch, progress, or other protected work. Return only the neutral decision to Common. |
| An authenticated Agent resumes a protected interaction in a later request | N/A | N/A | N/A | Resolve fresh authority and deny before restored-state disclosure, response validation, `resume()`, command/query dispatch, or progress when the Tool is no longer available. |
| Agent lifecycle or Permission authority changes between requests | N/A | N/A | N/A | The next request observes the changed authoritative state and denies protected work. Work already authorized from one request-local snapshot is not cancelled retroactively. |

The integration itself dispatches no Fight Command, Query, or Event. An authorized Tool may later dispatch its own
existing use case through Common's Tool contract; that Tool behavior is not owned by this TICKET.

## Metadata, Validation, and Permission Rules

- `Fight\AccessControl\Application\AccessControl\Agent\Attribute\RequiresAgentPermission` declares one direct
  Agent Permission at the published Common Tool declaration boundary. It is repeatable and every declaration is
  required. Any future any-of policy requires a separate accepted construct.
- Every Tool in an Agent-protected composition declares at least one requirement. Public Tools use a separate
  unprotected composition; absence is not an implicit public-Tool convention.
- Composition validates all static requirement metadata and canonical-name mappings. An unexpectedly absent or
  malformed runtime mapping fails closed as unavailable.
- Permission checks use the current immutable `AuthenticatedAgentPrincipal` snapshot and canonical
  `PermissionName` equality. AccessControl does not introduce Agent Roles, OAuth-scope mapping, or a general policy
  engine.
- Static Tool requirement metadata may be cached across requests. Agent principals, authorization decisions, and
  filtered discovery results remain request-local. Authorization-sensitive discovery retains `ttlMs: 0` and
  `cacheScope: private`.
- Reflection may occur while constructing the catalog, but not on repeated availability checks against that
  integration instance. AccessControl does not reproduce Common's Tool naming rules or own a filesystem cache.

## Failure and Concealment Contract

- An unresolved Agent, an Agent missing any declared Permission, malformed runtime requirement state, and an
  unavailable Tool all produce the same neutral unavailable decision once execution reaches this integration.
- Fight Common retains ownership of the public unknown-or-unavailable protocol outcome. It receives no Agent,
  principal, Permission, diagnostic classification, or policy object from AccessControl.
- Consumer transport authentication may reject a request before MCP dispatch. This TICKET does not make OAuth
  authentication failures publicly equivalent to post-authentication Tool concealment.
- A denied request performs no Tool validation, `handle()` or `resume()` call, protected command/query dispatch,
  progress publication, or interaction-state disclosure.
- Authentication diagnostics remain server-observable through the existing secret-free Agent authentication
  boundary and are not added to the neutral availability result.

## Dependencies and Compatibility

- Fight Common must publish a compatible release containing the canonical Tool metadata and name resolution,
  request-scoped neutral availability boundary, discovery/invocation enforcement, and protected-interaction retry
  seams described by its MCP Tool requirements. This package must install that release before TASK-00035 can become
  ready for agent with exact public types and signatures.
- [TICKET-00002](00002-TICKET.md) supplies `CurrentAgentPrincipalProvider`, the immutable authenticated Agent and
  direct-Permission snapshot, nonce consumption, and credential/Permission-revision fencing.
- [TICKET-00003](00003-TICKET.md) supplies the shared authenticated-authority and `PrincipalPermission` snapshot
  contracts without turning the MCP availability decision into a general `SecurityContext` policy.
- [WF-008](../wayfinder/tickets/WF-008-agent-aware-mcp-authorization-contract.md) fixes the authorization metadata,
  ownership, caching, freshness, snapshot, and concealment decisions.
- The integration must remain within the Domain/Application package boundary. It adds no production Adapter layer,
  framework dependency, route, persistence schema, or production namespace outside the adopted package profile.
- New public AccessControl contracts must be additive and classified as public API. Existing Agent authentication,
  Permission assignment, Security context, and consumers not composing MCP remain behaviorally unchanged.

## Acceptance Evidence

- [ ] Published Fight Common contracts and a compatible installed release identify the exact Tool declaration target,
  canonical metadata/name source, availability signature, and retry enforcement seam without AccessControl copying
  Common naming or protocol logic.
- [ ] Composition tests prove one requirement, repeated conjunctive requirements, missing metadata, malformed
  metadata, duplicate/drifting canonical identity, and separation of protected and public Tool compositions.
- [ ] Discovery conformance proves permitted Tools remain visible, unresolved or under-permissioned Agents conceal
  Tools before ordering and pagination, only static metadata crosses requests, and authorization-sensitive cache
  metadata remains private with a zero TTL.
- [ ] Invocation conformance proves unknown and unavailable Tools have the same public Common outcome and that denial
  occurs before Tool validation, `handle()`, command/query dispatch, progress, or protected diagnostics.
- [ ] Interaction conformance proves a later request resolves fresh authority and denies before restored-state
  disclosure, input-response validation, `resume()`, command/query dispatch, or progress.
- [ ] Request-lifecycle tests prove one provider resolves and reuses one immutable principal snapshot within a request,
  while Agent revocation, credential revision, or Permission-assignment revision changes are observed by the next
  request. Revocation after resolution does not cancel work already authorized in that request.
- [ ] Boundary tests prove Common receives only its neutral availability value and AccessControl receives Common's
  resolved canonical metadata rather than a separately derived Tool name.
- [ ] Focused behavior checks, `./bin/planning-check`, and the canonical `./bin/build` pass with exact coverage when
  the later implementation TASK is complete.

## Exclusions

- Self-service Agent profile reads or name changes, their managed Permission identities, input/output schemas,
  idempotency, events, and failure classifications.
- Consumer routes, HTTP responses, authentication selection, OAuth claim or scope mapping, TLS, origins, rate
  limits, persistence adapters, framework composition, exposed-Tool selection, logging backends, and deployment.
- Fight Common Tool registration, canonical naming, discovery ordering/pagination, Tool selection, protocol errors,
  progress transport, interaction-state storage, or MCP wire representation.
- Agent Roles, public Tools inside an Agent-protected composition, any-of Permission semantics, cross-request
  principal caching, and AccessControl-owned filesystem metadata caches.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 35 | [TASK-00035](../tasks/00035-TASK.md) | Authorize Agent-protected MCP Tools | needs-info |
## Decisions and Progress

All EPIC-00005 requirements have one cohesive owner here: static repeatable metadata, canonical-name catalog,
conjunctive Permission evaluation, request-local principal freshness, shared discovery/invocation/retry enforcement,
concealment, cache posture, snapshot semantics, compatibility, and conformance evidence. No separate layer-based
TICKET is required. [TASK-00035](../tasks/00035-TASK.md) is the one cohesive implementation owner and remains
`needs-info` until a compatible published/installable Fight Common release permits exact API confirmation. Its
dependency-ordered metadata/catalog, availability-integration, and conformance/enforcement assignments remain
SUBTASKs under that one TASK, not separate durable TASKs.
