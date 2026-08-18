---
id: T-00006
title: Manage active sessions
status: ready-for-agent
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

- [ ] Session queries return immutable safe views and never aggregate or credential material.
- [ ] Self-service revocation cannot revoke an unrelated user's session.
- [ ] Super-administrator revocation requires authorization and makes the reasoned audit record durable with the mutation.
- [ ] Tests prove ownership denial, successful self-service revocation, authorized intervention, and audit atomicity.

## Exclusions

No admin UI, device fingerprinting, persistence records, or audit projection adapter.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
