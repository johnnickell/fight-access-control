---
id: T-00016
prd: PRD-00001
title: Extract the ManagedPolicy capability
status: done
blocked_by:
---

# Extract the ManagedPolicy capability

## Outcome

Managed policy definition, planning, preview, and reconciliation form one explicit `ManagedPolicy` capability
instead of being split across Permission and Role or represented as Query-owned planning data. Preview and apply
share the same policy model and repository-backed planner without a Command containing or invoking a Query.

## Acceptance Criteria

- [x] Managed policy definitions, the complete desired policy, plan items, change actions, plans, preview Query,
  reconciliation Command and Event, definition exceptions, planner, and handlers live under mirrored Domain and
  Application `ManagedPolicy` boundaries.
- [x] One immutable `ManagedPolicy` value owns the complete desired Permission and Role definitions, referenced
  Permission identities, validation, and canonical round-trip serialization; preview and reconciliation messages
  consume that value without either message containing or invoking the other.
- [x] `ReconcileManagedPolicyHandler` obtains authoritative Permission, Role, and User state through Domain
  repositories and the shared planner directly; no Query, QueryHandler, or QueryBus participates in the Command
  path. `PreviewManagedPolicyHandler` remains read-only and uses the same planner.
- [x] Permission and Role retain their aggregate state, identities, names, tiers, repositories, managed/custom
  invariants, and mutation methods without depending back on `ManagedPolicy` types.
- [x] Permission and Role aggregate-state failures use aggregate-owned exceptions, while invalid desired-policy
  definitions use exceptions owned by `ManagedPolicy`.
- [x] Existing deterministic ordering, preview/apply parity, reference fences, managed-role exact membership,
  custom-record preservation, atomic one-Unit-of-Work reconciliation, post-commit `ManagedPolicyReconciled`
  publication, and serialization behavior remain unchanged.
- [x] Tests mirror the new capability boundaries, reject the former Command-to-Query coupling, and retain exact
  executable statement coverage.

## Scope

### Out of Scope

No authorization-policy or observable behavior change, new read model or query, delivery event, custom-role or User
assignment semantic change, persistence adapter, schema, migration, HTTP contract, or UI work.

## Verification

- Focused ManagedPolicy, Permission, and Role tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- Implemented on `feature/t-00016-extract-managed-policy-capability` through three approved red-green slices:
  Domain policy contract, repository-backed preview, and atomic reconciliation.
- Added the immutable `ManagedPolicy` value and mirrored Domain/Application capability boundaries; Permission and
  Role now own their managed aggregate-state exceptions.
- The reconciliation handler calls the shared planner with `command->getPolicy()` and contains no Query,
  QueryHandler, or QueryBus dependency.
- Focused final seams passed: ManagedPolicy Domain 6 tests / 33 assertions, preview 4 / 18, reconciliation 10 / 70.
- `./bin/planning-check` passed with 17 records and 2 active after closure.
- `./bin/build` passed with 492 tests, 3,488 assertions, and exact 3,583/3,583 statement coverage after closure.
- Final independent Standards and Spec reviews reported no findings after three targeted refinements.
