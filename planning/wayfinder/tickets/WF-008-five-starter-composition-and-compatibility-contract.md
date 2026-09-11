# WF-008 — Five-Starter Composition and Compatibility Contract

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** Accepted starter maps and Symfony wire authority
**Map:** [OpenAPI Schema Components 0.2.0](../openapi-schema-components-0-2-map.md)
**Depends on:** WF-005, WF-006, WF-007

## Question

What clean-installed-package contract proves Symfony, Laravel, Slim, CodeIgniter, and Yii can compose the shared
components into their own single valid OpenAPI documents while preserving Symfony-led wire compatibility?

## Must decide

- Require each starter generator to scan the installed package `resources/openapi/` path and its own Actions,
  request/response DTOs, routes, security declarations, and project-specific components in one invocation.
- Forbid two-document generation/merge and define clear diagnostics for missing vendor resources, duplicate
  component names, unresolved references, and unsupported package versions.
- Define the normalized client-facing parity surface: paths, methods, operation IDs, payload schemas, error
  semantics, authentication behavior, and private realtime invalidation contract. Ordering and server metadata may
  remain local.
- Assign each starter ownership of its generator command, checked-in artifact, Swagger UI, drift check, servers,
  tags, paths, operations, security schemes, status codes, and framework errors.
- Define clean Composer installation, OpenAPI validation, Swagger rendering, generated-client compilation, and
  normalized parity evidence independently in all five repositories.

## Required evidence

- A five-row consumer matrix names the installed resource path, local scan paths, generator/drift commands,
  generated artifact, Swagger UI boundary, client generation command, and clean-install acceptance journey.
- Normalized comparisons reject missing/extra client-consumed operations, changed operation IDs or payloads, unsafe
  error differences, and incompatible authentication semantics while tolerating ordering/server metadata.
- Failures in one starter remain that repository's evidence failure rather than being hidden by package success.

## Resolution boundary

This ticket settles consumer composition and parity requirements. It does not implement or certify a starter,
publish 0.2.0, or make this package the owner of application OpenAPI documents.

## Resolution

Open. Blocked by WF-005, WF-006, and WF-007, and gated on accepted starter maps and Symfony wire authority.
