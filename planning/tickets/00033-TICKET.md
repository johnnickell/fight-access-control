---
id: T-00033
prd: PRD-00005
title: Publish composable OpenAPI schema components for v0.2.0
status: in-progress
blocked_by:
---

# Publish composable OpenAPI schema components for v0.2.0

## Outcome

Consumers can scan a package-owned, non-autoloaded OpenAPI metadata directory alongside their own models and receive
the complete stable `Fight.AccessControl.*` schema catalog, without adding an OpenAPI dependency to the package
runtime or core layers.

## Scope

- In scope: the tagged `johnnickell/fight-common ^1.2` contract; repository-wide adoption of its PHPCS baseline and
  grammatical public PHPDoc migration, including generic `ResultSet<T>` annotations for package query handlers and
  repositories; `swagger-php` development dependency and Composer suggestion; `openapi/`
  attribute anchors and portable
  bootstrap; every WF-006 Command, Query, authentication, safe-result, collection, creation, empty-success, and
  optional JSend-success component; concise composition guide and representative payload examples; changelog and
  release-candidate planning updates for `v0.2.0`.
- Out of scope: OpenAPI imports under `src/`, a production autoload namespace, endpoints, routes, HTTP adapters,
  cookie implementation, root document, Swagger UI, generic error envelopes, generated clients, and a hosted site.

## Acceptance Criteria

- [ ] Composer declares `zircote/swagger-php ^6.5` only in `require-dev` and `suggest`, without changing production
      dependencies or PSR-4 production autoloading.
- [ ] Composer requires tagged `johnnickell/fight-common ^1.2`; generic `ResultSet<T>` annotations used by package
      query handlers and repositories rely on that contract and remain covered by focused handler and repository tests.
- [ ] The repository adopts the Fight Common PHPCS baseline and all public PHPDoc introduced by this migration is
      grammatical, while preserving existing behavior and signatures.
- [ ] The documented ResultSet reconstruction remains bounded-context view translation: Agent and session mappings
      retain their context-specific work, and no shared abstraction is introduced without a contract-risk reduction.
- [ ] Non-autoloaded `openapi/` anchors and `openapi/bootstrap.php` generate the complete `Fight.AccessControl.*`
      catalog settled in WF-006 and do not introduce a Domain, Application, or production Adapter dependency.
- [ ] Component fields, requiredness, UUID and timestamp formats, enum values, secret flags, browser and portable
      authentication profiles, collections, creation result, mutation-result options, and JSend envelopes match
      ADR 0008.
- [ ] The browser authentication example omits a refresh credential; the portable token-set example includes it
      without a credential example value.
- [ ] A consumer guide gives the Composer, bootstrap, scan, composition, payload, JSend, browser-cookie, portable
      token-set, creation, mutation, and empty-success guidance needed to merge with a consumer-owned document.
- [ ] The documented local proof scans the package with a disposable consumer fixture and verifies generated JSON
      contains representative package and consumer components with expected required fields.
- [ ] `CHANGELOG.md`, README, planning projections, and release-candidate records accurately describe `v0.2.0`;
      tag creation, push, and publication remain separate effects.
- [ ] `./bin/planning-check` and `./bin/build` pass.

## Verification

- Run focused source tests where behavior needs coverage.
- Run the documented disposable local OpenAPI composition command and inspect its JSON output.
- Run `./bin/planning-check` and `./bin/build`.

## Completion Notes

Record the verified implementation, exact local composition evidence, package build evidence, and release-candidate
state only when the ticket is terminal.
