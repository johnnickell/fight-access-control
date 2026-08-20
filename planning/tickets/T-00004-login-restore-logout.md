---
id: T-00004
title: Login, cold restore, and current-session logout
status: done
parent: PRD-00001
blocked_by:
  - T-00003
branch: feature/t-00004-login-restore-logout
---

# Login, cold restore, and current-session logout

## Outcome

An active user can log in, restore an authenticated session after a cold load, and revoke only the current session
on logout without exposing whether an identity exists.

## Acceptance criteria

- [x] Login normalizes canonical email and returns generic failure behavior with a bounded throttling port.
- [x] Successful login creates the server-authoritative session and exposes only the framework-neutral result needed by consumers.
- [x] Cold restoration revalidates the authenticated session without requiring browser-persisted access tokens.
- [x] Logout revokes only the current refresh session and leaves other sessions active.
- [x] Tests demonstrate generic failures, account-state denial, restoration, and single-session revocation.

## Exclusions

No JWT implementation, cookie writing, browser storage, rate-limiter backend, or HTTP endpoint.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- `Login` and `LoginHandler` canonicalize email, apply bounded throttling before one real or dummy password
  verification, reject every failed outcome generically, and create an authoritative refresh session inside one
  Unit of Work before publishing the safe `UserLoggedIn` result.
- `RestoreAuthenticatedSessionHandler` returns a framework-neutral view only for an unrevoked session whose
  owner is active and whose authoritative authentication version still matches.
- `LogoutCurrentSessionHandler` obtains the consumer-authoritative current session through an Application port,
  revokes only that aggregate inside one Unit of Work, and leaves sibling sessions restorable.
- Command failures publish Fight Common `CommandFailedEvent` with the original command, then rethrow the same
  failure; success events publish only after commit.
- `./bin/planning-check` and `./bin/build` passed. The final build ran 127 tests with 650 assertions and enforced
  exact statement coverage at 546/546, plus PHPCS, PHPStan, architecture, package-boundary, Rector,
  documentation-link, and production-autoload checks.
- Coordinate evidence is retained under `.runs/2026-08-19-t-00004-login-restore-logout/` and is intentionally
  ignored from version control.
