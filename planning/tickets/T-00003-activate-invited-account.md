---
id: T-00003
title: Activate an invited account
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00001
branch: feature/t-00003-activate-invited-account
---

# Activate an invited account

## Outcome

An invited person can redeem one valid activation grant, choose an initial password, transition to active, and end
the journey authenticated through a newly created first session.

## Acceptance criteria

- [ ] Activation accepts only a matching, unexpired, unused purpose-bound grant.
- [ ] Successful activation sets the credential, consumes the grant, activates the identity, and creates the first session atomically.
- [ ] Replay, expiry, mismatch, and non-pending identity outcomes are rejected without partial transition.
- [ ] Tests cover aggregate invariants, Application transaction ownership, and conformance behavior.

## Exclusions

No browser cookie, JWT signing, password-hash implementation, persistence adapter, or HTTP flow.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
