---
id: T-00001
title: Invite a pending user
status: done
parent: PRD-00001
blocked_by: []
branch: feature/t-00001-invite-pending-user
---

# Invite a pending user

## Outcome

An inviter can create one canonically unique pending identity. The transaction creates its purpose-bound activation
grant, encrypted pending delivery work, and required secret-free audit evidence before email work can be attempted.

## Acceptance criteria

- [x] Canonical email uniqueness covers pending, active, disabled, and deleted identities.
- [x] Invitation persists the User, activation grant, pending delivery work, and required audit evidence atomically.
- [x] No raw activation credential is persisted outside the approved recoverable delivery boundary.
- [x] Domain and Application tests demonstrate the successful and rejected invitation outcomes through in-memory ports.
- [x] The reusable conformance suite captures these observable outcomes without framework dependencies.

## Exclusions

No mail transport, encryption-key implementation, persistence adapter, HTTP action, or framework integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Evidence

- `./bin/planning-check` passed after ticket and board synchronization.
- `./bin/build` passed with 53 tests, 282 assertions, and 76/76 exact statement coverage.
- The consumer-bindable invitation conformance contract proves normalized success and canonical-email rejection.
