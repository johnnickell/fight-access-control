---
id: T-00014
title: Unify password-reset grant persistence
status: done
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
- [x] Repository operations preserve grant identity, latest-generation delivery identity, historical credential
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
- Initial issuance, terminal append, and replacement-with-successor now reject nonzero revisions, terminal authority,
  non-recoverable delivery, ownership mismatches, reused identities or digests, and every non-pristine successor state
  without mutation.
- Concrete runtime subclasses survive issue and reconstitution plus delivery invalidation, confirmation, expiry,
  aggregate consumption, revocation, and owned-delivery replacement transitions.
- The post-fix grant-focused regression run passed 108 tests with 574 assertions.
- Final `./bin/planning-check` passed with 2 records and 2 active. Final `./bin/build` passed 300 tests with 1,934
  assertions and exact statement coverage at 1,675/1,675; PHPCS, PHPStan, architecture, package boundaries, Rector,
  documentation, and production autoload checks passed.
