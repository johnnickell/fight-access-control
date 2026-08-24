---
id: T-00005
prd: PRD-00001
title: Secure refresh-session rotation
status: done
blocked_by: T-00004
---

# Secure refresh-session rotation

## Outcome

A caller receives rotation outcomes from an authoritative server-side refresh session: one winner, a bounded
benign conflict outcome, and family revocation for credential reuse outside that window.

## Acceptance Criteria

- [x] Refresh sessions own rotation, revocation, activity, idle and absolute lifetime, and authentication version.
- [x] A successful rotation emits exactly one new credential result; a bounded concurrent conflict emits no credential.
- [x] Reuse outside the accepted conflict window fails closed by revoking the session family.
- [x] Remember-me changes only refresh-session persistence and lifetime, never access-token authority or lifetime.
- [x] Tests cover rotation, race conflict, replay compromise, timeout, and post-revocation behavior.

## Scope

### Out of Scope

No database locking implementation, cookie construction, signing-key adapter, cache adapter, or client refresh
coordination.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- `AuthenticationService::refresh()` returns an explicit immutable `ROTATED` or `CONFLICT` result. Only a
  rotation winner receives a new opaque refresh credential and access JWT; bounded conflicts contain no token
  material and do not publish failure events.
- `RefreshSession` owns immutable revision, credential-digest history, monotonic activity, idle and absolute
  lifetime, authentication version, and revocation. Rotation advances idle activity without extending the
  absolute deadline, and remember-me never changes access-token claims or its 15-minute lifetime.
- Revision-based repository compare-and-replace permits one rotation winner and prevents stale rotation from
  resurrecting concurrent revocation. Revocation retries are bounded and fail closed under persistent
  contention.
- Only the immediately preceding credential inside the explicitly configured conflict interval is benign.
  Older or late used credentials resolve the authoritative family, commit revocation, then raise the same
  generic terminal failure with redacted context.
- Tests cover sequential and interleaved rotation races, `C0 → C1 → C2` replay, timeout, authentication-version
  mismatch, concurrent revocation, retry exhaustion, and post-compromise behavior.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 124 tests with 765 assertions and exact statement
  coverage at 724/724; PHPCS, PHPStan, architecture, package-boundary, Rector, documentation, and production
  autoload checks passed. Independent Standards and Spec reviews both passed.
