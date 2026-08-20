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

An active user can log in through the synchronous authentication service, receive a short-lived access JWT plus
an opaque refresh credential, restore authentication after a cold load, and revoke only the current session on
logout without exposing whether an identity exists.

## Acceptance criteria

- [x] Secret-bearing activation and login are synchronous `AuthenticationService` methods and never serializable Commands.
- [x] Login uses Fight Common password validation, normalizes canonical email, and returns generic failure behavior with a bounded throttling port.
- [x] Activation hashes the submitted password inside the service and never serializes the raw activation credential or password.
- [x] Successful activation and login atomically create an authoritative refresh session and return a safe token set containing a 15-minute access JWT and one opaque refresh credential.
- [x] Cold restoration revalidates account/session authority and returns a new access JWT without browser-persisted access tokens.
- [x] Logout resolves and revokes only the submitted current refresh credential while leaving sibling sessions active.
- [x] Tests demonstrate redacted failures, account-state denial, token claims and lifetimes, restoration, and single-session revocation.

## Exclusions

No HTTP endpoint, cookie construction, signing-key adapter, rate-limiter backend, or framework-native session support.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- `AuthenticationService` is the single non-CQRS seam for activation, login, cold refresh, and logout. Raw
  passwords, activation credentials, and refresh credentials exist only as `#[SensitiveParameter]` arguments.
- The service uses Fight Common `PasswordHasher`, `PasswordValidator`, and `TokenEncoder`; no parallel password
  interface remains. Sensitive failures publish only `RedactedCommandFailed` with allowlisted context.
- `TokenSet` returns a 15-minute access JWT plus one opaque refresh credential. `RefreshSession` stores only its
  digest and enforces the recovered ordinary one-day idle/two-day absolute and remembered 15-day idle/30-day
  absolute lifetime policy.
- Cold refresh revalidates active account state, ownership, authentication version, revocation, and expiry before
  issuing a new JWT. Logout resolves authority from the presented refresh credential and revokes only that session.
- The local PRD, engineering guidance, ADR 0003, and T-00013 now restore the supported editable React client and
  explicitly classify framework-native session authentication as an unsupported fallback.
- Red-first focused PHPUnit failed before the service existed, then passed with 14 tests and 123 assertions. The
  final `./bin/build` passed 104 tests with 584 assertions and exact statement coverage at 617/617, plus PHPCS,
  PHPStan, Deptrac, Rector, planning, documentation-link, package-boundary, and production-autoload checks.
- Coordinate evidence remains under `.runs/2026-08-19-t-00004-login-restore-logout/` and is intentionally ignored
  from version control.
