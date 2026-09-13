# Set lightweight composition proof and v0.2.0 handoff

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Map:** [OpenAPI schema components for v0.2.0](../openapi-schema-components-v0-2-0-map.md)
**Depends on:** [Define authentication payload and JSend response catalog](WF-006-authentication-payload-jsend-catalog.md)

## Question

What is the smallest reliable local proof that the published components generate the intended JSON and compose with
a consumer-owned model, and what implementation and release records are required before preparing `v0.2.0`?

## Must decide

- The supported local generator command and the minimal expected JSON assertions or inspection evidence.
- Whether a fast contract check belongs in the recurring package build, or generation remains an explicit release
  verification command.
- The consumer-facing composition example, without supplying a root document, routes, or a complete documentation
  build.
- The epic, PRD, implementation-ticket, version, changelog, and release-candidate handoff required to move from
  planning to `v0.2.0` preparation.

## Resolution boundary

This ticket settles proof and handoff only. It does not publish a tag, release, documentation site, client, or
consumer integration.

## Resolution

Write this only when the decision is closed. Link the resulting handoff where relevant.
