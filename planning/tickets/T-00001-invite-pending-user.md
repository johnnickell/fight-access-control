---
id: T-00001
title: Invite a pending user
status: ready-for-agent
parent: PRD-00001
blocked_by: []
branch: feature/t-00001-invite-pending-user
---

# Invite a pending user

## Outcome

An inviter can create one canonically unique pending identity. The transaction creates its purpose-bound activation
grant, encrypted pending delivery work, and required secret-free audit evidence before email work can be attempted.

## Acceptance criteria

- [ ] Canonical email uniqueness covers pending, active, disabled, and deleted identities.
- [ ] Invitation persists the User, activation grant, pending delivery work, and required audit evidence atomically.
- [ ] No raw activation credential is persisted outside the approved recoverable delivery boundary.
- [ ] Domain and Application tests demonstrate the successful and rejected invitation outcomes through in-memory ports.
- [ ] The reusable conformance suite captures these observable outcomes without framework dependencies.

## Exclusions

No mail transport, encryption-key implementation, persistence adapter, HTTP action, or framework integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
