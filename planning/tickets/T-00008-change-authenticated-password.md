---
id: T-00008
title: Change an authenticated password
status: done
parent: PRD-00001
blocked_by:
  - T-00004
branch: feature/t-00008-change-authenticated-password
---

# Change an authenticated password

## Outcome

An authenticated active user can change a password only by proving the current password; the change applies the
defined authentication-version, audit, and session effects.

## Acceptance criteria

- [x] The command requires an authenticated owner and current-password verification.
- [x] Incorrect current-password and invalid account-state outcomes do not mutate credentials or sessions.
- [x] Successful change updates the credential and makes required audit evidence durable with the mutation.
- [x] Tests prove authorization, current-password proof, audit durability, and revalidation effects.

## Exclusions

No password manager UI, HTTP action, hash algorithm, or session-store adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- The synchronous `AuthenticationService::changePassword()` seam accepts only the consumer-authenticated caller
  identity and sensitive current/new password arguments. Missing, inactive, and incorrect-proof authority use one
  generic rejection and publish only the caller ID through redacted failure evidence.
- Successful proof replaces the password through the `User` aggregate, advances authentication version and the
  authentication-authority revision exactly once, and persists through the existing authority CAS and
  transaction-duration fence.
- The same Unit of Work revokes every active refresh session, including the current session, and records
  `user.password_changed` audit evidence. No replacement token or session is issued; `PasswordChanged` publishes
  only after commit.
- Executable failure proofs cover lost authority CAS, a later session-revocation write failure, and late audit
  failure. Each rethrows unchanged, rolls back all staged authority/session/audit effects, and publishes no secret
  input or success event.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 227 tests with 1740 assertions and exact statement
  coverage at 1326/1326; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation, and production
  autoload checks passed. Independent Standards and Spec reviews reported no findings.
