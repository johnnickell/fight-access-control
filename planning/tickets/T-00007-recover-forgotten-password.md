---
id: T-00007
title: Recover a forgotten password
status: ready-for-agent
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

- [ ] Reset requests return generic outcomes and create delivery work only when appropriate.
- [ ] Reset grants are purpose-bound, hashed, expiring, single-use, and unrelated to activation grants.
- [ ] Successful reset changes the credential and revokes every active session atomically.
- [ ] Tests prove generic response, grant replay and expiry rejection, session revocation, and durable audit behavior.

## Exclusions

No email transport, password-hash implementation, reset page, or persistence adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
