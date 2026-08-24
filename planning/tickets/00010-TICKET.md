---
id: T-00010
prd: PRD-00001
title: Change and correct identity journeys
status: done
blocked_by: T-00002,T-00007,T-00009
---

# Change and correct identity journeys

## Outcome

An active user can reserve and confirm a new email address without displacing the old one prematurely; authorized
assistance follows the same confirmation journey, and a pending invitation can be corrected atomically.

## Acceptance Criteria

- [x] Email change reserves the destination until confirmed; cancellation and expiry release only that reservation.
- [x] Confirmation uses an unrelated, hashed, expiring, single-use grant and revokes sessions before requiring new login.
- [x] Super-administrator initiation or cancellation cannot bypass mailbox confirmation and is durably audited.
- [x] Pending-invitation correction replaces its prior address, grant, and delivery atomically.
- [x] Tests prove uniqueness, confirmation, cancellation, expiry, authorization, and predecessor rejection.

## Scope

### Out of Scope

No mailbox implementation, HTTP confirmation page, persistence unique-index implementation, or admin UI.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- Email-change reservation, cancellation, expiry, invocation-neutral encrypted delivery, terminal successor
  generations, and synchronous secret-bearing confirmation are implemented through framework-neutral Domain and
  Application seams. `AuthenticationService::confirmEmail()` consumes one-time authority, promotes the reserved
  identity, advances authentication authority, revokes every active session, and emits only secret-free outcomes.
- Administrative initiation and cancellation use the narrow `EmailChangeAdministrationAuthorization` port and
  persist required audit evidence atomically. Pending-invitation correction uses the separate
  `InvitationAdministrationAuthorization` port and atomically replaces the canonical email, activation predecessor,
  successor grant, and recoverable delivery.
- Executable concurrency coverage prevents stale authentication and role-assignment replacements from erasing email
  state, fences stale delivery callbacks and grant generations, and proves rollback for uniqueness, CAS, transport,
  session-revocation, and audit failures.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 387 tests with 2,714 assertions and exact statement
  coverage at 2,342/2,342; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation, and production
  autoload checks passed. Independent Standards and Spec reviews reported no findings.
