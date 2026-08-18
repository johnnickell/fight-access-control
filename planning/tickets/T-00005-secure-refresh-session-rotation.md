---
id: T-00005
title: Secure refresh-session rotation
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00004
branch: feature/t-00005-secure-refresh-session-rotation
---

# Secure refresh-session rotation

## Outcome

An authenticated browser receives rotation outcomes from an authoritative server-side refresh session: one winner,
a bounded benign conflict outcome, and family revocation for credential reuse outside that window.

## Acceptance criteria

- [ ] Refresh sessions own rotation, revocation, activity, idle and absolute lifetime, and authentication version.
- [ ] A successful rotation emits exactly one new credential result; a bounded concurrent conflict emits no credential.
- [ ] Reuse outside the accepted conflict window fails closed by revoking the session family.
- [ ] Remember-me changes only refresh-session persistence and lifetime, never access-token authority or lifetime.
- [ ] Tests cover rotation, race conflict, replay compromise, timeout, and post-revocation behavior.

## Exclusions

No database locking implementation, cookie transport, JWT signing, browser replay logic, or cache adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
