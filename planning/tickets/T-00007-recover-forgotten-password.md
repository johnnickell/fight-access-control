---
id: T-00007
title: Recover a forgotten password
status: done
parent: PRD-00001
blocked_by:
  - T-00004
branch: feature/t-00007-recover-forgotten-password
---

# Recover a forgotten password

## Outcome

A person can request password recovery without account enumeration, then redeem a one-time expiring reset grant
that changes the credential and revokes all sessions.

## Acceptance criteria

- [x] Reset requests return generic outcomes and create delivery work only when appropriate.
- [x] Reset grants are purpose-bound, hashed, expiring, single-use, and unrelated to activation grants.
- [x] Successful reset changes the credential and revokes every active session atomically.
- [x] Tests prove generic response, grant replay and expiry rejection, session revocation, and durable audit behavior.

## Exclusions

No email transport, password-hash implementation, reset page, or persistence adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- Generic request handling performs identity lookup, active-user eligibility, reset-grant creation, encrypted
  delivery-work creation, and secret-free audit persistence in one Unit of Work. Unknown and ineligible identities
  follow the same public no-work outcome.
- Reset authority is hashed, purpose-bound, one-hour expiring, single-use, historically unique per user, and
  protected by explicit compare-and-set semantics for initial issuance, reissue, terminal append, and consumption.
- Delivery work has immutable generation identity. Confirmation and terminal expiry destroy recoverable ciphertext;
  stale callbacks cannot affect newer work, and delivery is not authoritative during redemption.
- Synchronous redemption changes the password, advances authentication authority, consumes the grant, revokes all
  active sessions, and records durable audit evidence atomically. A monotonic User authority revision, atomic
  authority-plus-session insertion, and a transaction-duration per-user fence close login/reset races.
- Generic and redacted failures cover malformed, wrong, expired, consumed, revoked, missing, replayed, and
  concurrency-lost credentials without exposing secrets or account existence.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 221 tests with 1613 assertions and exact statement
  coverage at 1262/1262; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation, and production
  autoload checks passed. Independent Standards and Spec reviews reported no remaining findings.
