# WF-007 — Package Generation and Validation Contract

**Labels:** `wayfinder:prototype`
**Mode:** HITL
**Status:** Open
**Gate:** —
**Map:** [OpenAPI Schema Components 0.2.0](../openapi-schema-components-0-2-map.md)
**Depends on:** WF-005, WF-006

## Question

How will the package generate and validate its component catalog, detect drift, and prove the built Composer archive
contains usable metadata without claiming ownership of a complete application API document?

## Must decide

- Define the package-owned generator/validator command and a test fixture that supplies only the minimal root
  metadata required to validate all shared components and references.
- Define OpenAPI 3.1 validation, stable serialization/drift comparison, example validation, reference resolution,
  duplicate-name rejection, and safe diagnostics.
- Define how source and clean Composer archives are checked for the exact `resources/openapi/` manifest while
  confirming the carriers are not autoloaded.
- Keep generated package-side evidence clearly internal to validation; it is not a supported full API document and
  is not distributed as an operation authority.
- Place owning-tool and human-inspection checks in the build without adding tests that merely inspect wrapper,
  configuration, CI, Markdown, or generated text.

## Required evidence

- The planned command generates one valid OpenAPI 3.1 fixture document from the package resources and fails on a
  broken reference, duplicate component, incompatible drift, or missing archive resource.
- A clean-install probe scans the installed resource path successfully without loading carrier classes at runtime.
- The handoff distinguishes exact production-code coverage from generator/configuration verification.

## Resolution boundary

This ticket settles package validation and archive evidence. It does not implement the command, publish a package,
or transfer operation/transport ownership into Fight AccessControl.

## Resolution

Open. Blocked by WF-005 and WF-006.
