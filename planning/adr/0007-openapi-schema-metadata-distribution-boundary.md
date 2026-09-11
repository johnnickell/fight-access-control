# ADR 0007: OpenAPI Schema Metadata Distribution Boundary

- Status: accepted
- Date: 2026-09-10

## Context

The 0.1.0 package boundary deliberately excluded HTTP, framework, browser-client, and transport ownership. Five
starter applications need stable, reusable OpenAPI schemas for the same package-owned concepts without annotating
Domain or Application classes, copying schema definitions into every framework, or making this package the owner of
their HTTP APIs.

This is an additive 0.2.0 boundary decision. It does not rewrite or weaken the historical 0.1.0 package decisions.

## Decision

Fight AccessControl 0.2.0 may distribute scan-only PHP attribute carriers under `resources/openapi/`. Those files
describe reusable OpenAPI 3.1 component schemas for package-owned User, Role, Permission, pagination, validation,
and safe-error concepts. They are schema metadata only: they are excluded from Composer autoloading, are not a
supported runtime class API, and never annotate or introduce HTTP dependencies into Domain or Application code.

The package does not own paths, operations, operation IDs, servers, tags, security schemes, status codes,
framework-specific errors, Swagger UI, generated clients, or transport implementations. Each starter owns those
elements and generates exactly one OpenAPI document in one pass by scanning both the installed package resource
directory and its project-owned HTTP attributes. A starter must not generate and merge separate specifications.

Published component names and their wire meaning are versioned public metadata. Additive compatible components or
optional fields may ship in a minor release; removing, renaming, changing requiredness, narrowing accepted values, or
otherwise breaking an existing component requires the next major release. The package build owns catalog generation
and validation through a package-side fixture, while each starter owns clean-installed-package composition and wire
compatibility evidence.

## Consequences

Consumers share stable schema vocabulary without sharing an HTTP implementation. Framework-native adapters and
their complete API documents remain locally owned, and a clean Composer install contains everything a starter needs
to scan the shared components without reaching into source checkouts or package internals.
