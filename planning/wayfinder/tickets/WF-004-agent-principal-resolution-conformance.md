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

Write this only when the decision is closed. Link the epic, PRD, or implementation-ticket handoff created by the
resolved map where relevant.
