# Set lightweight composition proof and v0.2.0 handoff

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
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
- The EPIC, TICKET, implementation TASK, version, changelog, and release-candidate handoff required to move from
  planning to `v0.2.0` preparation.

## Resolution boundary

This ticket settles proof and handoff only. It does not publish a tag, release, documentation site, client, or
consumer integration.

## Resolution

The release uses an explicit local composition proof, outside the recurring build. A disposable consumer fixture
defines an OpenAPI `Info` object and one consumer schema, then a consumer runs `swagger-php` with both the fixture
bootstrap and `openapi/bootstrap.php`. The generated JSON must contain the consumer component, representative
`Fight.AccessControl.*` components, and the expected required fields. The command and inspection evidence live in
the focused consumer guide and a task-owned `.runs/` directory; no package-owned root document or integration suite
is created.

The release includes a concise composition guide covering Composer installation, bootstrap and scan use, canonical
payloads, browser cookie and portable token-set profiles, optional JSend success envelopes, creation results,
mutation results, and empty success. It is a focused first guide and may inform a later documentation-quality pass;
that broader effort does not block `v0.2.0`.

[EPIC-00004](../../epics/00004-EPIC.md), [TICKET-00005](../../tickets/00005-TICKET.md), and
[TASK-00033](../../tasks/00033-TASK.md) are the implementation handoff. The ticket prepares the changelog and
release candidate after its local proof and canonical gates pass. Tagging, push, and publication remain separately
authorized effects.
