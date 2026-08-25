# Specify Agent principal resolution and conformance

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Map:** [Agent HMAC authentication and direct authority](../agent-hmac-authentication-map.md)
**Depends on:** [Establish Agent credential and revocation lifecycle](WF-002-agent-credential-revocation-lifecycle.md), [Protect Agent Permission reference integrity](WF-003-agent-permission-reference-integrity.md)

## Question

What separate immutable Agent-principal snapshot, request-scoped resolution seam, and conformance outcomes prove
authentication and direct authority without conflating machine principals with User refresh sessions or making the
package enforce consumer authorization policy?

## Must decide

- The typed Agent principal and current-principal provider shape, including its authoritative revalidation inputs
  and cache scope.
- The fail-closed conditions for revoked authority, invalid credentials, replay, missing Permissions, and stale
  assignment references.
- The boundary between package-owned authentication/permission presence and consumer-owned permission checks.
- The aggregate, Application, and consumer-conformance scenarios necessary to prove the behavior across frameworks.

## Resolution boundary

This ticket may define portable principal resolution and behavioral evidence. It must not merge User and Agent
principal types prematurely, add framework integration, prescribe consumer authorization checks, or create a
transport-specific conformance suite.

## Resolution

Agent authentication resolves a distinct immutable `AuthenticatedAgentPrincipal`, never a User principal, refresh
session, `AgentView`, or follow-up Permission query. Its authoritative snapshot contains the Agent ID, current
credential ID and revision, Permission-assignment revision, and direct Permission snapshots by ID and canonical
name. It can report only whether that snapshot contains a named Permission.

A consumer composes a request-scoped `CurrentAgentPrincipalProvider` that authenticates one signed request and caches
the immutable result for that request. Resolution fails closed with one generic caller-visible denial whenever Agent
authority, credential currency, request authentication, or an assigned Permission reference is invalid. A secret-free
diagnostic classification and consumer correlation identifier remain available for server observability; they exclude
raw requests, signatures, nonces, and shared secrets.

Consumers retain authorization policy, transport response mapping, and logging or metric delivery. Package
conformance proves the complete snapshot, once-per-request resolution, generic fail-closed outcomes with safe
diagnostics, direct-Permission presence only, and consumer adaptation into `SignedAgentRequest` without prescribing
headers, middleware, or denial responses. [ADR 0006](../../adr/0006-agent-principal-observability-boundary.md)
records the durable boundary.

All Agent HMAC Wayfinder decisions are settled. WF-004 remains open until its required canonical build verification
completes; afterwards, the map requires a separately approved `/to-spec` handoff to create implementation planning
artifacts.
