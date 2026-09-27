# Set atomic tier enforcement proof

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John settled the package proof boundary on 2026-09-26.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-013, WF-014, WF-015

## Question

How must package commands handle a concurrent Permission grant and promotion to `SUPER_ADMIN_ONLY`, and what
package evidence is required?

## Accepted decision (2026-09-26)

- A grant to a custom Role or Agent and a promotion of the same Permission to `SUPER_ADMIN_ONLY` cannot both
  succeed. If the grant commits first, reconciliation rejects the promotion while that membership remains. If
  promotion commits first, the grant is rejected. A losing command leaves no partial change or success event.
- Package handlers make tier and membership decisions inside their Unit of Work. Repository contracts must preserve
  the authoritative Permission tier through the decision and write, including an idempotent grant, and reject a
  conflicting change. The concrete database locking method is an adapter implementation choice.
- Focused package unit tests must exercise the decision and failure paths, including a controlled concurrent or
  stale-state interleaving. The normal `./bin/build` gate still applies when implementation changes are made. A
  unit test proves package behavior, not a particular PostgreSQL lock implementation.
- The application builder protects command entry points and owns caller audit and HTTP error presentation under
  WF-013 and WF-014. Consumer PostgreSQL, HTTP, and full-build checks belong to its later adoption work, not this
  package Wayfinder gate. This decision does not require an Agent OS data cleanup migration.

## Required evidence

- Inspected transaction-wrapped package handlers, post-commit success events, current reference fences, in-memory
  repositories, and the first consumer's PostgreSQL Permission and Role repositories. Existing reference fences
  protect against deletion, but do not establish a tier-stability guarantee for concurrent grant and promotion.
- Fight Agent OS currently locks Role Permission references, stores custom Permission tiers as null, and has no
  production Agent Permission repository. Its adapter changes and tests are separate adoption work.

## Resolution boundary

Set the package's atomic outcome and focused proof. EPIC/TICKET/TASK planning is a separate handoff. Do not
implement, tag, or update Agent OS dependencies here.

## Resolution

Closed. John accepted the atomic outcome and limited this ticket's acceptance evidence to package unit tests plus
the package's normal implementation gate. No implementation is authorized.
