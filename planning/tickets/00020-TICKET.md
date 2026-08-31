---
id: T-00020
prd: PRD-00002
title: Rotate and revoke an Agent credential
status: done
blocked_by: T-00019
---

# Rotate and revoke an Agent credential

## Outcome

A maintainer can replace an Agent credential immediately or revoke the Agent permanently. Rotation rejects a stale
expected credential, never leaves a grace credential, and returns a replacement secret only after the replacement
authority and required audit evidence commit.

## Scope

- In scope: atomic rotate and revoke operations, credential revision changes, expected-credential fencing,
  secret-free audit evidence and success events, and failure ordering.
- Out of scope: signed-request verification, nonce consumption, direct Permission assignment, recovery of a revoked
  Agent, scheduled expiry, production persistence, and secret-delivery transport.

## Acceptance Criteria

- [x] Rotation replaces the sole active credential immediately and advances its revision.
- [x] Stale rotation work fails without changing the Agent or exposing a secret.
- [x] Revocation is terminal; a revoked Agent cannot rotate or authenticate.
- [x] Successful operations commit required audit evidence before publishing their success events.
- [x] Tests cover normal and stale rotation, revocation, failures, and exact coverage.

## Verification

- Focused Agent credential-lifecycle aggregate and Application tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- Added immutable Agent credential-successor and terminal-revocation transitions, with expected-credential fencing,
  monotonic revisions, and a repository compare-and-replace contract for authoritative persistence.
- Added a synchronous credential-lifecycle service that commits the replacement/revocation and secret-free audit
  evidence atomically before publishing safe success events. Rotation returns its non-serializable raw-secret result
  only after commit; failures rethrow unchanged and publish only generic secret-free evidence.
- Added aggregate, repository, and Application tests covering normal and stale rotation, terminal revocation,
  ordering, rollback, serialization safety, and failure publication behavior.
- `./bin/planning-check` passed with 28 records and 7 active records. `./bin/build` passed with planning integrity,
  PHPCS, PHPStan, architecture, package boundaries, Rector, PHPUnit, and exact statement coverage.
