---
id: T-00020
prd: PRD-00002
title: Rotate and revoke an Agent credential
status: ready-for-agent
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

- [ ] Rotation replaces the sole active credential immediately and advances its revision.
- [ ] Stale rotation work fails without changing the Agent or exposing a secret.
- [ ] Revocation is terminal; a revoked Agent cannot rotate or authenticate.
- [ ] Successful operations commit required audit evidence before publishing their success events.
- [ ] Tests cover normal and stale rotation, revocation, failures, and exact coverage.

## Verification

- Focused Agent credential-lifecycle aggregate and Application tests
- `./bin/planning-check`
- `./bin/build`
