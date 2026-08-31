---
id: T-00015
prd: PRD-00001
title: Unify activation-grant persistence
status: done
blocked_by: T-00001,T-00002,T-00003
---

# Unify activation-grant persistence

## Outcome

Activation authority and its delivery entity share one aggregate repository boundary while preserving invitation,
resend, delivery recovery, and activation guarantees.

## Acceptance Criteria

- [x] ActivationGrant and its delivery entity have stable IDs and are persisted solely through
  `ActivationGrantRepository`; the separate delivery repository contract and handler dependency are removed.
- [x] Repository operations preserve latest-generation lookup and explicit compare-and-set semantics for initial
  issuance, resend replacement, delivery transitions, and activation consumption.
- [x] Resend atomically revokes the predecessor, creates the newest grant and recoverable delivery generation,
  and ensures only the latest credential can activate the pending User.
- [x] Confirmation and terminal expiry destroy recoverable ciphertext, while stale callbacks and stale generation
  IDs cannot mutate, expire, or confirm newer delivery work.
- [x] Tests preserve T-00001 through T-00003 atomic rollback, retry, predecessor rejection, replay, expiry,
  concurrent replacement, durable audit, and post-commit event behavior.

## Scope

### Out of Scope

No invitation or activation policy change, credential format change, mail or queue implementation, persistence
adapter, schema, or migration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- ActivationGrant owns its delivery generation and is persisted solely through `ActivationGrantRepository`; the
  former split delivery repository and handler dependencies are absent.
- Repository CAS covers initial issue, resend replacement, delivery transitions, and consumption while preserving
  historical digest uniqueness and latest-generation lookup.
- Resend revokes the predecessor atomically, stale delivery callbacks cannot affect newer generations, and terminal
  delivery outcomes destroy recoverable ciphertext without changing invitation or activation policy.
- Activation delivery outcomes now require `CLAIMED`; aggregate guards and repository transition validation reject
  pending, failed, confirmed, expired, stale, or fabricated bypasses without changing authoritative state.
- Initial issuance and successor insertion require revision-zero issued authority with correctly owned pending
  recoverable delivery, globally fresh grant and delivery IDs, and a historically fresh per-user digest.
- Concrete runtime subclasses survive issue and reconstitution plus claim, retry, confirmation, failure, invalidation,
  expiry, consumption, revocation, and owned-delivery replacement transitions.
- The post-fix grant-focused regression run passed 108 tests with 574 assertions.
- Final `./bin/planning-check` passed with 2 records and 2 active. Final `./bin/build` passed 300 tests with 1,934
  assertions and exact statement coverage at 1,675/1,675; PHPCS, PHPStan, architecture, package boundaries, Rector,
  documentation, and production autoload checks passed.
