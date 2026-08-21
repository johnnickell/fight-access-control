---
id: T-00006
title: Manage active sessions
status: done
parent: PRD-00001
blocked_by:
  - T-00004
branch: feature/t-00006-manage-active-sessions
---

# Manage active sessions

## Outcome

A user can inspect coarse information about active sessions and revoke another one; an authorized super
administrator can inspect and revoke another user's session with an auditable reason.

## Acceptance criteria

- [x] Session queries return immutable safe views and never aggregate or credential material.
- [x] Self-service revocation cannot revoke an unrelated user's session.
- [x] Super-administrator revocation requires authorization and makes the reasoned audit record durable with the mutation.
- [x] Tests prove ownership denial, successful self-service revocation, authorized intervention, and audit atomicity.

## Exclusions

No admin UI, device fingerprinting, persistence records, or audit projection adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- `ListActiveSessions` contains the canonical Fight Common `Pagination` value, and `ListActiveSessionsHandler`
  returns a metadata-preserving `ResultSet` containing only immutable, credential-free `SessionView` values for
  currently usable owner-scoped sessions. Cross-user inspection authorizes before reading the session repository.
- `RevokeSessionHandler` revokes an owned non-current session through revision compare-and-replace in one Unit of
  Work and dispatches the safe success event only after commit.
- Cross-user revocation requires the narrow session-administration authorization port and a trimmed Unicode-aware
  reason bounded to 1–500 characters. The reason is durable only in secret-free audit evidence and is excluded
  from the success event.
- Administrative revocation and its audit evidence commit atomically; a late audit write failure rolls back the
  session replacement, dispatches `CommandFailedEvent`, and rethrows the original failure.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 141 tests with 894 assertions and exact statement
  coverage at 895/895; PHPCS, PHPStan, architecture, package-boundary, Rector, documentation, and production
  autoload checks passed. Independent Standards and Spec reviews reported no remaining P0–P3 findings.
