---
id: T-00008
title: Change an authenticated password
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00004
branch: feature/t-00008-change-authenticated-password
---

# Change an authenticated password

## Outcome

An authenticated active user can change a password only by proving the current password; the change applies the
defined authentication-version, audit, and session effects.

## Acceptance criteria

- [ ] The command requires an authenticated owner and current-password verification.
- [ ] Incorrect current-password and invalid account-state outcomes do not mutate credentials or sessions.
- [ ] Successful change updates the credential and makes required audit evidence durable with the mutation.
- [ ] Tests prove authorization, current-password proof, audit durability, and revalidation effects.

## Exclusions

No password manager UI, HTTP action, hash algorithm, or session-store adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
