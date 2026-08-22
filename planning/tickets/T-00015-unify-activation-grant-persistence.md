---
id: T-00015
title: Unify activation-grant persistence
status: in-progress
parent: PRD-00001
blocked_by:
  - T-00001
  - T-00002
  - T-00003
branch: feature/t-00015-unify-activation-grant-persistence
---

# Unify activation-grant persistence

## Outcome

Activation authority and its delivery entity share one aggregate repository boundary while preserving invitation,
resend, delivery recovery, and activation guarantees.

## Acceptance criteria

- [x] ActivationGrant and its delivery entity have stable IDs and are persisted solely through
  `ActivationGrantRepository`; the separate delivery repository contract and handler dependency are removed.
- [ ] Repository operations preserve latest-generation lookup and explicit compare-and-set semantics for initial
  issuance, resend replacement, delivery transitions, and activation consumption.
- [x] Resend atomically revokes the predecessor, creates the newest grant and recoverable delivery generation,
  and ensures only the latest credential can activate the pending User.
- [x] Confirmation and terminal expiry destroy recoverable ciphertext, while stale callbacks and stale generation
  IDs cannot mutate, expire, or confirm newer delivery work.
- [x] Tests preserve T-00001 through T-00003 atomic rollback, retry, predecessor rejection, replay, expiry,
  concurrent replacement, durable audit, and post-commit event behavior.

## Exclusions

No invitation or activation policy change, credential format change, mail or queue implementation, persistence
adapter, schema, or migration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- ActivationGrant owns its delivery generation and is persisted solely through `ActivationGrantRepository`; the
  former split delivery repository and handler dependencies are absent.
- Repository CAS covers initial issue, resend replacement, delivery transitions, and consumption while preserving
  historical digest uniqueness and latest-generation lookup.
- Resend revokes the predecessor atomically, stale delivery callbacks cannot affect newer generations, and terminal
  delivery outcomes destroy recoverable ciphertext without changing invitation or activation policy.
- The focused PasswordResetGrant and ActivationGrant regression run passed 86 tests with 373 assertions; final
  repository verification is recorded by the shared completion gate rather than attributed to this slice alone.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 278 tests with 1,722 assertions and exact statement
  coverage at 1,689/1,689; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation, and production
  autoload checks passed.
- Final Standards review remains blocked because claim-before-confirm/fail is not enforced as an aggregate/repository
  invariant, issuance accepts already transitioned generations, and extensible transitions do not preserve subtypes.
