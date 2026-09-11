# WF-006 — Scan-Only Carrier and Versioning Contract

**Labels:** `wayfinder:prototype`
**Mode:** HITL
**Status:** Open
**Gate:** —
**Map:** [OpenAPI Schema Components 0.2.0](../openapi-schema-components-0-2-map.md)
**Depends on:** WF-005

## Question

What scan-only PHP attribute layout and semantic-versioning contract makes the shared catalog stable and usable
without creating a runtime API or coupling package layers to OpenAPI?

## Must decide

- Define the `resources/openapi/` directory, carrier naming, one-component/reference conventions, attribute style,
  file discovery, and Composer archive inclusion.
- Prove the carrier files are excluded from Composer autoloading and never annotate or import Domain/Application
  classes as metadata hosts.
- Set stable component names and reference identifiers, including collision avoidance with starter-owned components.
- Classify additive optional fields/components versus breaking removal, rename, requiredness, type, enum, format, or
  semantic changes under the package's 0.x and future stable version policy.
- Define deprecation and migration-note expectations for any published component evolution.

## Required evidence

- A disposable scanner prototype resolves every WF-005 component and reference from only the resource directory.
- Composer autoload inspection proves the carrier namespace/files are absent from the supported runtime class API.
- A compatibility table classifies representative additive and breaking schema changes unambiguously.

## Resolution boundary

This ticket settles metadata layout and compatibility. It does not create production carriers, complete OpenAPI
documents, HTTP operations, Swagger UI, generated clients, or framework dependencies.

## Resolution

Open. Blocked by WF-005.
