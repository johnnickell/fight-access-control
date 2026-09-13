# Choose OpenAPI metadata ownership and component discovery

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Map:** [OpenAPI schema components for v0.2.0](../openapi-schema-components-v0-2-0-map.md)
**Depends on:** —

## Question

How should the framework-neutral package publish PHP OpenAPI metadata so a consumer generator discovers reusable
components without turning package code into a transport adapter or forcing a package-owned OpenAPI document?

## Must decide

- The supported attribute library and version policy, including whether it is a production dependency.
- Which package-owned public types receive attributes and whether dedicated presentation-only schema types are
  required where runtime types contain secrets or are intentionally non-serializable.
- Stable component naming, namespace collision avoidance, consumer scan/discovery instructions, and the compatibility
  policy for published schema names.
- How the decision supersedes the current broad OpenAPI exclusion while retaining consumer ownership of endpoints,
  security, HTTP behavior, and document assembly.

## Resolution boundary

This ticket settles component publication and discovery only. It does not choose authentication fields, response
envelopes, HTTP status codes, paths, cookies, or a documentation build.

## Resolution

Write this only when the decision is closed. Link the resulting handoff where relevant.
