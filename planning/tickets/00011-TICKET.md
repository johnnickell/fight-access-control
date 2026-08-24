---
id: T-00011
prd: PRD-00001
title: Administer account lifecycle
status: done
blocked_by: T-00006,T-00009
---

# Administer account lifecycle

## Outcome

Authorized administrators can disable, enable, soft-delete, and restore one stable identity while user and session
administration remains safe, paginated, and auditable.

## Acceptance Criteria

- [x] Disable immediately revokes sessions; enable does not restore prior sessions.
- [x] Delete and restore retain stable identity and canonical-email uniqueness without permitting duplicate reinvitation.
- [x] User and session queries return typed pages of immutable safe views.
- [x] Every classified sensitive lifecycle mutation has durable secret-free audit evidence.
- [x] Tests prove authorization, state transitions, session effects, canonical uniqueness, and safe query output.

## Scope

### Out of Scope

No retention-job implementation, database soft-delete mapping, admin UI, or HTTP endpoint.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- The `User` aggregate owns `disable()`, `enable()`, `delete()`, and `restore(UserState)` transitions with
  `UserLifecycleException` guards. Restoration targets `ACTIVE` (retaining the established password) or
  `PENDING_ACTIVATION` (clearing the password and issuing a fresh activation grant), with both constrained to those
  two `UserState` values.
- Four explicit `DisableUser`, `EnableUser`, `DeleteUser`, and `RestoreUser` commands and their handlers persist the
  transition through the new `UserRepository::replaceLifecycleState()` compare-and-set boundary, revoke every active
  session through the shared `SessionRevocationService`, and record secret-free `user.*` audit evidence atomically
  before publishing their success event after commit. Authorization is permissioned by the consuming project; `actorId`
  is audit-only.
- `SessionRevocationService` centralizes the retry/CAS session-revocation loop previously duplicated inside
  `AuthenticationService`, which now delegates to it. Restoration to `PENDING_ACTIVATION` issues a pristine successor
  activation grant via the new `ActivationGrantRepository::addSuccessor()` boundary and routes delivery through the
  existing invitation-delivery subscriber.
- `ListUsers` and `UserView` expose a typed page of safe, immutable views (identifier, canonical email, lifecycle state,
  and role assignments only). Final `./bin/planning-check` passed. Final build passed 433 tests with 2,941 assertions
  and exact statement coverage at 2,646/2,646; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation,
  and production autoload checks passed.
