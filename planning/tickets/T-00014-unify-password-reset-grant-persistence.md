---
id: T-00014
title: Unify password-reset grant persistence
status: in-progress
parent: PRD-00001
blocked_by:
  - T-00007
branch: feature/t-00014-unify-password-reset-grant-persistence
---

# Unify password-reset grant persistence

## Outcome

Password-reset authority and its delivery entity share one aggregate repository boundary without weakening the
completed recovery lifecycle's concurrency, replay, secrecy, or rollback guarantees.

## Acceptance criteria

- [x] `PasswordResetGrantRepository` is the sole Domain persistence contract for PasswordResetGrant and its
  delivery entity; the separate delivery repository contract and handler dependency are removed.
- [ ] Repository operations preserve grant identity, latest-generation delivery identity, historical credential
  uniqueness, and compare-and-set semantics for issuance, reissue, terminal delivery changes, and consumption.
- [x] Confirmation and terminal expiry destroy recoverable ciphertext, and stale callbacks cannot mutate or
  invalidate newer delivery generations.
- [x] Password reset preserves T-00007 generic and redacted failures, one-time replay and expiry rejection,
  complete session revocation, per-user authority fencing, and atomic audit durability.
- [x] Tests prove race losers and stale callbacks are mutation-free, failed Units of Work roll back grant and
  delivery state together, and all T-00007 observable behavior remains unchanged.

## Exclusions

No recovery-policy change, credential format change, email transport, persistence adapter, schema, or migration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- Password-reset grant and delivery behavior now live under one `PasswordResetGrant` aggregate boundary and one
  `PasswordResetGrantRepository`; the separate delivery repository and split handler dependencies are absent.
- The aggregate repository preserves historical credential uniqueness, current-generation identity, explicit CAS,
  stale-callback rejection, ciphertext destruction, and atomic rollback of grant and delivery state.
- Existing recovery behavior remains generic and secret-redacted while consumption retains authentication-authority
  fencing, complete session revocation, replay/expiry rejection, and durable audit atomicity.
- The focused PasswordResetGrant and ActivationGrant regression run passed 86 tests with 373 assertions; final
  repository verification is recorded by the shared completion gate rather than attributed to this slice alone.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 278 tests with 1,722 assertions and exact statement
  coverage at 1,689/1,689; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation, and production
  autoload checks passed.
- Final Standards review remains blocked because repository issuance does not yet reject non-initial or already
  transitioned generations, and extensible aggregate transitions do not preserve runtime subtypes.
