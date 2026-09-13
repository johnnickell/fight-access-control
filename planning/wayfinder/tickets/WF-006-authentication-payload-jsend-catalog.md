# Define authentication payload and JSend response catalog

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Map:** [OpenAPI schema components for v0.2.0](../openapi-schema-components-v0-2-0-map.md)
**Depends on:** [Choose OpenAPI metadata ownership and component discovery](WF-005-openapi-metadata-ownership.md)

## Question

Which public payloads and optional JSend response components should `v0.2.0` publish, and what exact secure shapes
will show a consumer the expected login, refresh, activation, and administrative outputs?

## Must decide

- The initial public catalog, including existing safe views and each authentication input, successful result, and
  bounded refresh-conflict result.
- Canonical field names, requiredness, formats, examples, timestamps, identifier representations, and enum values.
- The distinction between token-result data and consumer transport: whether a raw refresh credential is represented
  as a separately documented body payload, while cookie delivery remains consumer-owned.
- JSend success, fail, and error component boundaries; reusable per-payload envelopes; and which failures are safe
  to describe without leaking authentication details.
- Documentation of delete/no-content and other empty-success outputs without prescribing consumer routes or HTTP
  status choices.

## Resolution boundary

This ticket settles the schema catalog and examples only. It does not implement attributes, alter runtime
serialization, create HTTP adapters, or select a consumer project's routes, cookies, error mapping, or UI.

## Resolution

Write this only when the decision is closed. Link the resulting handoff where relevant.
