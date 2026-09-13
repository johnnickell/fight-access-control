# Wayfinder Map: OpenAPI schema components for v0.2.0

**Label:** `wayfinder:map`
**Status:** Active

> This map is an **index, not a store**. Each material decision lives in exactly one linked ticket under
> `tickets/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Produce an implementation-ready handoff for reusable PHP OpenAPI schema components that consumer projects scan
alongside their own models and assemble into their own OpenAPI documents. The package will describe its public
payloads, including authentication input and output payloads, and optional JSend response envelopes. The handoff
will define the work required for a verified `v0.2.0` release candidate.

**Done** = every linked decision ticket is closed, the remaining fog is resolved or excluded, and the map links
to its resulting epic, PRDs, and/or implementation tickets.

## Notes

- The package remains framework-neutral: consumers own routes, HTTP status choices, cookies, response creation,
  application-specific models, and documentation UI.
- The schema components must be PHP attributes that consumer-owned OpenAPI generation processes can scan. Static
  standalone specification files and a Swagger documentation build are excluded.
- The catalog must make expected login, refresh, activation, and administrative output shapes inspectable. It must
  distinguish portable payload data from optional JSend wrapping and never make secret-bearing PHP results
  serializable.
- Local generation may prove the components produce expected JSON. Recurring build coverage is appropriate only
  if it remains simple, fast, and materially protects the public contract.

## Decisions so far

1. **Charting constraints are settled.** Consumer projects need composable PHP schema attributes, not a complete
   package-owned OpenAPI document or Swagger UI.
2. **Payload visibility is settled.** The catalog includes public authentication inputs and outputs as well as
   existing safe administrative payloads.
3. **Envelope direction is settled.** The package should offer payload components and optional JSend response
   components, allowing consumers to use JSend without imposing it on their own APIs.

## Tickets

| Ticket | Type | Mode | Status | Depends On |
|---|---|---|---|---|
| [Choose OpenAPI metadata ownership and component discovery](tickets/WF-005-openapi-metadata-ownership.md) | Grilling / Domain Modeling | HITL | **Open** | — |
| [Define authentication payload and JSend response catalog](tickets/WF-006-authentication-payload-jsend-catalog.md) | Grilling / Domain Modeling | HITL | **Open** | WF-005 |
| [Set lightweight composition proof and v0.2.0 handoff](tickets/WF-007-openapi-composition-proof-release-handoff.md) | Grilling | HITL | **Open** | WF-006 |

## Blocking relationships

```text
Metadata ownership and component discovery ──→ Authentication payload and JSend response catalog ──→ Composition proof and v0.2.0 handoff
```

## Frontier

[Choose OpenAPI metadata ownership and component discovery](tickets/WF-005-openapi-metadata-ownership.md) is the
one next grillable decision.

## Not yet specified (fog)

- Whether source-level attributes can make an intended schema change a release-blocking compatibility concern
  without requiring a separate schema-diff tool.
- Which additional public command and query messages belong in the initial catalog after the authentication and
  administrative components are enumerated.

## Out of scope

- Package-owned API routes, controllers, middleware, HTTP response creation, cookies, security schemes, or an
  OpenAPI root document.
- Swagger UI, a hosted documentation site, generated clients, TypeScript models, or browser applications.
- A mandatory consumer integration or slow recurring documentation-generation test in this package build.
