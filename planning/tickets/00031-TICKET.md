---
id: T-00031
prd: PRD-00004
title: Make custom Role Permission changes safe to retry
status: done
blocked_by:
---

# Make custom Role Permission changes safe to retry

## Outcome

An authorized consumer can safely retry granting a Permission to a custom Role or revoking it. An already-correct
custom-Role membership completes normally without recording a change, while managed Roles, missing definitions,
and concurrency loss continue to fail hard.

## Scope

- In scope: desired-state custom-Role Permission grant and revocation, managed-Role invariants, authorization and
  reference validation, exact Permission resolution, final Permission-reference fencing, transactional replacement,
  existing events, failure publication, and extension of the shared mutation behavioral matrix.
- Out of scope: changing Managed Role definitions outside managed-policy reconciliation, Agent Permission behavior,
  User Role behavior, direct User Permissions, generic Role or Permission commands, production adapters, and
  framework authorization policy.

## Acceptance Criteria

- [x] Granting an already-contained Permission and revoking an already-absent Permission from a custom Role complete
  normally as desired-state no-ops.
- [x] Role-administration authorization, target Role existence, custom rather than managed ownership, and referenced
  Permission existence are validated before either no-op is accepted.
- [x] A no-op performs no Role repository replacement, changes no timestamp, and publishes no grant, revoke, or
  synthetic no-op event.
- [x] A real grant or revocation uses one `commitTransactional()` boundary, one Role-owned state transition, one
  compare-and-replace persistence operation, and its existing purpose-specific success event after commit.
- [x] Final Permission-reference or compare-and-replace authority loss fails without partial Role state or a success
  event.
- [x] Managed Roles remain runtime-immutable, and Permission-removal reference-integrity ownership is unchanged.
- [x] Every failure dispatches `CommandFailedEvent` for the original command and rethrows the identical `Throwable`.
- [x] The shared authorization-modification behavioral matrix proves custom-Role real changes and no-op retries
  alongside Agent and User outcomes, including validation, writes, timestamps, event ordering, and failure identity.
- [x] Public commands, authorization ports, events, aggregate behavior, and Domain repository ownership remain
  purpose-specific while reused coordinators stay final and `@internal`.

## Verification

- Focused custom-Role desired-state Domain tests
- Custom-Role Permission command-handler matrix, reference-fence, and failure-ordering tests
- Public-boundary and architecture checks
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Delivered desired-state custom-Role Permission grants and revocations with authorization and reference validation
before no-op acceptance, non-writing/timestamp-preserving retries, final Permission-reference fencing, preserved
post-commit purpose-specific events, and original-Throwable failure publication/rethrow. Focused checks,
`./bin/planning-check`, and the canonical exact-coverage `./bin/build` passed on 2026-08-30.
