# WF-005 — Shared Schema Component Catalog

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** —
**Map:** [OpenAPI Schema Components 0.2.0](../openapi-schema-components-0-2-map.md)
**Depends on:** —

## Question

What minimal reusable OpenAPI 3.1 schema catalog represents package-owned AccessControl concepts without taking
ownership of any consumer's HTTP API?

## Must decide

- Enumerate the User, Role, Permission, identifiers, timestamps, lifecycle states, authority summaries, and
  relationship shapes that are genuinely shared across consumers.
- Define reusable pagination request/response metadata, validation-detail, safe failure, and unexpected-error
  components without embedding framework exception classes or route semantics.
- Separate public administrative views from secret-bearing credentials, internal coordinators, persistence records,
  audit internals, and transport-only request shapes that must never enter the shared catalog.
- Define requiredness, nullability, formats, bounds, enums, examples, and reference composition precisely enough for
  generated TypeScript clients and negative request/response validation.
- Establish how future package capabilities join the catalog without forcing every public PHP type into OpenAPI.

## Required evidence

- A catalog table maps every proposed component to a released package concept and at least one consumer use.
- A negative inventory identifies secrets, internal types, framework errors, paths, operations, security schemes,
  and other intentionally excluded metadata.
- Symfony and all four downstream starter maps can express their complete operation matrices using the catalog plus
  locally owned request/response components.

## Resolution boundary

This ticket settles component scope and semantics. It does not choose PHP carrier structure, generator commands,
starter operations, or implementation tickets.

## Resolution

Open. This is the current Wayfinder frontier.
