---
id: TICKET-00005
legacy_id: PRD-00005
epic: EPIC-00004
title: Composable OpenAPI Schema Components
status: done
---

# Composable OpenAPI Schema Components

## Problem Statement

Fight AccessControl defines portable Domain and Application values, but a consuming project cannot presently merge
their shapes into its own OpenAPI generation process. Recreating those shapes in every project invites drift and
does not show a consumer the expected authentication, administrative, collection, creation, or optional JSend
outputs. Putting documentation attributes in the core layers would violate the framework-neutral package boundary.
The repository also needs to adopt the released Fight Common PHPCS baseline and complete its public PHPDoc migration
without letting documentation-only work silently escape the implementation TASK that owns the dependency upgrade.

## Solution

Ship non-autoloaded `swagger-php` schema anchors and a bootstrap in the package root `openapi/` directory. Consumers
install the suggested generator and scan that directory with their own models. Publish stable
`Fight.AccessControl.*` components for every public Command and Query input, safe Query result, authentication-service
input and result, typed collection, shared creation result, optional JSend success envelope, and empty success.

The browser authentication response excludes the refresh credential; the portable token-set response includes it
for explicit body-token or non-browser profiles. Request-only credentials and passwords are `writeOnly` with no
examples. Consumers retain ownership of routes, parameter placement, status codes, headers, cookies, failures,
error mapping, security schemes, document roots, and documentation UI.

## Implementation Decisions

- Add `zircote/swagger-php ^6.5` under `require-dev` and Composer `suggest`, with no production dependency or
  production autoload namespace.
- Keep all OpenAPI imports outside `src/`, in non-autoloaded schema anchors loaded by `openapi/bootstrap.php`.
- Use canonical `toArray()` field names and snake case; context IDs are UUID strings, timestamps are `date-time`,
  and enums expose serialized values.
- Cover all catalogued inputs and safe outputs from WF-006, including exact Fight Common pagination collections.
- Provide optional typed JSend success envelopes only. A body-bearing empty success has `data: null`; HTTP 204 has
  no body.
- Provide `CreatedResource` with `id` for a consumer that returns one created resource identity. A mutation may
  return an applicable safe resource; deletion uses empty success.
- Add concise consumer composition guidance and payload examples. This focused guide may inform a later broader
  documentation-quality push, which does not block `v0.2.0`.
- Adopt the Fight Common PHPCS baseline and repair the migration's public PHPDoc prose without changing behavior or
  signatures.
- Keep ResultSet reconstruction in each query handler as bounded-context view translation. Agent and session
  mappings add context-specific behavior; a shared abstraction would increase coupling without reducing a material
  contract risk.

## Testing Decisions

- Add focused tests for the schema-anchor source and bootstrap only where they exercise package behavior; do not
  create meta-tests that inspect tooling configuration.
- Run one documented explicit local command that scans package metadata with a disposable consumer fixture and
  inspect generated JSON for representative package and consumer component keys and required fields.
- Keep composition generation outside the recurring `./bin/build` gate unless a later review establishes a fast,
  material contract check.
- Completion requires `./bin/planning-check` and `./bin/build`, with exact coverage maintained for all `src/`
  production statements.

## Out of Scope

- A production OpenAPI dependency, OpenAPI imports in Domain or Application, or a production Adapter layer.
- Package-owned routes, controllers, middleware, HTTP responses, cookies, security schemes, root OpenAPI document,
  Swagger UI, hosted documentation site, generated client, or consumer integration suite.
- Generic JSend fail or error contracts.
- A broader documentation redesign beyond the focused consumer composition guide.

## Further Notes

This Ticket implements [ADR 0007](../adr/0007-openapi-schema-metadata-distribution.md),
[ADR 0008](../adr/0008-openapi-payload-contract.md), and the
[OpenAPI Wayfinder map](../wayfinder/openapi-schema-components-v0-2-0-map.md).

Tasks: TASK-00033. Completed 2026-09-13 as a local review candidate; tagging, release, and publication remain separate effects.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 33 | [TASK-00033](../tasks/00033-TASK.md) | Publish composable OpenAPI schema components for v0.2.0 | done |
