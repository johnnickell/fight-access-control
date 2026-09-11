# OpenAPI Schema Components 0.2.0

**Label:** `wayfinder:map`
**Status:** Active

> This map is an **index, not a store**. Each material decision lives in exactly one linked ticket under
> `tickets/`; this map summarizes the decision frontier and blocking relationships.

## Destination

Chart an implementation-ready additive 0.2.0 release of reusable, scan-only PHP OpenAPI 3.1 component schemas for
Fight AccessControl consumers. The package supplies stable User, Role, Permission, pagination, validation, and safe
error components under `resources/openapi/`; each starter combines those resources with its own HTTP attributes in
one generation pass and retains ownership of its complete document and transport behavior.

**Done** = WF-005 through WF-009 are closed; the catalog, carrier layout, semantic-versioning rules, package-side
validation, clean-installed consumer composition, and release handoff are settled; remaining fog is resolved or
excluded; and WF-009 links the resulting epic, PRDs, and implementation tickets created by the later approved
handoff workflow.

## Notes

- [ADR 0007](../adr/0007-openapi-schema-metadata-distribution-boundary.md) is the additive 0.2.0 boundary. Completed
  0.1.0 decisions and the closed Agent map remain historical authority and are not retroactively rewritten.
- The carriers are schema metadata, not Composer-autoloaded runtime classes. Domain and Application remain free of
  OpenAPI, HTTP, and framework dependencies.
- The package owns components only. Starters own paths, operations, operation IDs, servers, tags, security schemes,
  status codes, framework-specific errors, Swagger UI, generated clients, and transport implementations.
- One generator invocation receives multiple source paths: installed `resources/openapi/` plus project-owned HTTP
  sources. Generating and merging two specifications is outside the accepted composition seam.
- Symfony is the canonical wire-contract and editable-client leader. Laravel, Slim, CodeIgniter, and Yii reproduce
  its client-facing contract through framework-native backends and locally generated documents.
- This map plans the release only. It creates no schema carrier, dependency, generated artifact, client, tag, or
  package publication effect.

## Decisions so far

1. **[Shared Schema Component Catalog](tickets/WF-005-shared-schema-component-catalog.md) is open.** Define every
   reusable component and its ownership boundary.
2. **[Scan-Only Carrier and Versioning Contract](tickets/WF-006-scan-only-carrier-and-versioning-contract.md) is
   open.** Set the resource layout, attribute form, names, references, and compatibility rules.
3. **[Package Generation and Validation Contract](tickets/WF-007-package-generation-and-validation-contract.md) is
   open.** Define package-side generation, validation, drift, and installed-artifact checks.
4. **[Five-Starter Composition and Compatibility Contract](tickets/WF-008-five-starter-composition-and-compatibility-contract.md)
   is open.** Define one-pass consumer scanning and normalized Symfony parity evidence.
5. **[0.2.0 Implementation Handoff Acceptance](tickets/WF-009-openapi-components-handoff-acceptance.md) is open.**
   Reconcile the decisions and define the later epic/PRD/ticket handoff without creating it during charting.

## Tickets

| Ticket | Type | Mode | Status | Depends On | Gate |
|---|---|---|---|---|---|
| [WF-005 — Shared Schema Component Catalog](tickets/WF-005-shared-schema-component-catalog.md) | Grilling | HITL | **Open** | — | — |
| [WF-006 — Scan-Only Carrier and Versioning Contract](tickets/WF-006-scan-only-carrier-and-versioning-contract.md) | Prototype | HITL | **Open** | WF-005 | — |
| [WF-007 — Package Generation and Validation Contract](tickets/WF-007-package-generation-and-validation-contract.md) | Prototype | HITL | **Open** | WF-005, WF-006 | — |
| [WF-008 — Five-Starter Composition and Compatibility Contract](tickets/WF-008-five-starter-composition-and-compatibility-contract.md) | Grilling | HITL | **Open** | WF-005, WF-006, WF-007 | Accepted starter maps and Symfony wire authority |
| [WF-009 — 0.2.0 Implementation Handoff Acceptance](tickets/WF-009-openapi-components-handoff-acceptance.md) | Grilling | HITL | **Open** | WF-005 through WF-008 | Human approval of the handoff |

## Blocking relationships

```text
WF-005 ──→ WF-006 ──→ WF-007 ──→ WF-008 ──→ WF-009 ──→ approved epic / PRDs / implementation tickets
                                  ↑
accepted five-starter maps + Symfony wire authority
```

## Frontier

[WF-005 — Shared Schema Component Catalog](tickets/WF-005-shared-schema-component-catalog.md) is the one next
grillable decision. Run `$aios /grill-with-docs WF-005`.

## Not yet specified (fog)

- Exact component names, field requiredness, formats, enum representation, pagination shape, validation pointers,
  safe error codes, examples, and reference structure.
- Exact carrier filenames/classes, attribute style, scanner configuration, package fixture, generated validation
  artifact, drift command, and Composer archive inclusion checks.
- Exact normalized parity representation and clean-install harness used by each starter.
- The final epic/PRD split, implementation slices, and release qualification sequence; WF-009 owns that handoff.

## Out of scope

- HTTP paths or operations, security schemes, servers, tags, status codes, framework errors, Swagger UI, generated
  clients, or transport implementations in this package.
- Annotating Domain/Application classes or exposing schema carriers as an autoloaded runtime API.
- Generating and merging independent package and starter specifications.
- Implementing starter APIs or clients, publishing 0.2.0, creating implementation tickets during charting,
  archiving completed records, or changing completed 0.1.0 decisions.
