# Wayfinder Map: OpenAPI schema components for v0.2.0

**Label:** `wayfinder:map`
**Status:** Closed

> This map is an **index, not a store**. Each material decision lives in exactly one linked ticket under
> `tasks/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Produce an implementation-ready handoff for reusable PHP OpenAPI schema components that consumer projects scan
alongside their own models and assemble into their own OpenAPI documents. The package will describe its public
payloads, including authentication input and output payloads, and optional JSend response envelopes. The handoff
will define the work required for a verified `v0.2.0` release candidate.

**Done** = every linked decision ticket is closed, the remaining fog is resolved or excluded, and the map links
to its resulting epic, Tasks, and/or implementation tasks.

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
4. **Metadata ownership is settled.** `swagger-php ^6.5` remains a development dependency and Composer suggestion.
   Non-autoloaded anchors in `openapi/`, loaded with `openapi/bootstrap.php`, are the only package-owned OpenAPI
   metadata; Domain and Application remain free of OpenAPI imports.
5. **Payload contract is settled.** Every public Command and Query, safe read result, and authentication operation
   has a component using canonical values, typed collections, optional JSend success envelopes, browser-safe and
   portable token profiles, and explicit creation or empty-success results.
6. **Proof and handoff are settled.** One explicit local consumer-composition scan is release evidence outside the
   recurring build. A focused guide ships with the implementation, while a broader documentation-quality pass may
   follow without blocking `v0.2.0`.

## Tasks

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-005 | [Choose OpenAPI metadata ownership and component discovery](tickets/WF-005-openapi-metadata-ownership.md) | Grilling / Domain Modeling | HITL | **Closed** | — |
| WF-006 | [Define authentication payload and JSend response catalog](tickets/WF-006-authentication-payload-jsend-catalog.md) | Grilling / Domain Modeling | HITL | **Closed** | WF-005 |
| WF-007 | [Set lightweight composition proof and v0.2.0 handoff](tickets/WF-007-openapi-composition-proof-release-handoff.md) | Grilling | HITL | **Closed** | WF-006 |

## Blocking relationships

```text
Metadata ownership and component discovery ──→ Authentication payload and JSend response catalog ──→ Composition proof and v0.2.0 handoff
```

## Frontier

No Wayfinder decision remains. The implementation handoff is recorded in the
[Wayfinder map index](README.md).

## Compatibility resolution

Published schema keys and fields are public `0.x` contracts. An intended source-level attribute change that removes,
renames, narrows, or makes a field required is release-blocking until it is released under the compatible minor-version
policy and recorded in the changelog. TASK-00033 verifies this initial catalog through the documented local composition
proof; a separate schema-diff tool is not required for this release.

## Out of scope

- Package-owned API routes, controllers, middleware, HTTP response creation, cookies, security schemes, or an
  OpenAPI root document.
- Swagger UI, a hosted documentation site, generated clients, TypeScript models, or browser applications.
- A mandatory consumer integration or slow recurring documentation-generation test in this package build.

## Resolution

[EPIC-00004](../epics/00004-EPIC.md), [TICKET-00005](../tickets/00005-TICKET.md), and
[TASK-00033](../tasks/00033-TASK.md) implement this map. The focused consumer guide is release work; a later
broader documentation-quality pass is a non-blocking follow-on decision.
