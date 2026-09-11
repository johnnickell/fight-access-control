# WF-009 — 0.2.0 Implementation Handoff Acceptance

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** Human approval of the handoff
**Map:** [OpenAPI Schema Components 0.2.0](../openapi-schema-components-0-2-map.md)
**Depends on:** WF-005, WF-006, WF-007, WF-008

## Question

What epic, PRDs, vertical implementation tickets, release gates, and consumer evidence are required to deliver the
additive 0.2.0 schema-components boundary without hiding unresolved decisions in execution work?

## Must decide

- Reconcile the catalog, carrier/versioning, package validation, and five-starter composition decisions with ADR
  0007 and the existing package boundary.
- Define the smallest coherent implementation slices for resource carriers, package validation, archive inclusion,
  documentation, semantic drift, and five independent consumer handoffs.
- Gate starter implementation on installable Fight Common 1.2.0 and Fight AccessControl 0.2.0; do not invent an
  intermediate 0.1.x contract release.
- Require package `./bin/planning-check` and `./bin/build`, one valid locally generated document and rendered Swagger
  UI per starter, normalized Symfony parity, successful generated-client compilation, and each starter's canonical
  full build.
- Preserve commit, push, PR, tag, publication, starter implementation, and archive as separate later effects.

## Required evidence

- Every closed WF decision maps to an epic/PRD requirement, vertical implementation ticket, acceptance journey,
  documentation obligation, release gate, or explicit exclusion.
- The future handoff includes clean-installed-package verification from all five starters and no test that merely
  inspects wrappers, configuration, CI, Markdown, or generated files.
- Human approval is explicit before the map closes or implementation planning is generated.

## Resolution boundary

This ticket may define and, only after explicit human approval, link the implementation planning handoff. It does
not implement schemas, create implementation tickets during charting, publish 0.2.0, modify starter code, archive
records, commit, or push.

## Resolution

Open. Blocked by WF-005 through WF-008 and gated on human handoff approval.
