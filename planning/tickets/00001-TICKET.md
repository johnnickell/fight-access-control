---
id: T-00001
prd: PRD-00001
title: Invite a pending user
status: done
blocked_by:
---

# Invite a pending user

## Outcome

An inviter can create one canonically unique pending identity. The transaction creates its purpose-bound activation
grant, encrypted pending delivery work, and required secret-free audit evidence before email work can be attempted.

## Acceptance Criteria

- [x] Canonical email uniqueness covers pending, active, disabled, and deleted identities.
- [x] Invitation persists the User, activation grant, pending delivery work, and required audit evidence atomically.
- [x] No raw activation credential is persisted outside the approved recoverable delivery boundary.
- [x] Domain and Application tests demonstrate the successful and rejected invitation outcomes through in-memory ports.
- [x] Canonical Command, Event, and CommandHandler tests capture successful and rejected invitation outcomes without framework dependencies.

## Scope

### Out of Scope

No mail transport, encryption-key implementation, persistence adapter, HTTP action, or framework integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- `./bin/planning-check` passed after ticket and board synchronization.
- `./bin/build` passed with 54 tests, 290 assertions, and 113/113 exact statement coverage.
- The build regression-tests the exact coverage gate: coverage-ignore directives, malformed reports, missing metrics,
  and incomplete statement coverage fail the build.
- The invitation follows the established `Domain\\AccessControl\\User` and `Application\\AccessControl\\User` Command/Event/Handler layout, reuses Fight Common values, uses Domain repository interfaces with atomic `add()` operations, and retains no public conformance-contract surface.
