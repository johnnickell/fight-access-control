---
id: T-00003
title: Activate an invited account
status: done
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

- [x] Activation accepts only a matching, unexpired, unused purpose-bound grant.
- [x] Successful activation sets the credential, consumes the grant, activates the identity, and creates the first session atomically.
- [x] Replay, expiry, mismatch, and non-pending identity outcomes are rejected without partial transition.
- [x] Tests cover aggregate invariants, Application transaction ownership, and conformance behavior.

## Exclusions

No browser cookie, JWT signing, password-hash implementation, persistence adapter, or HTTP flow.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- T-00003 originally delivered activation through `ActivateInvitedAccount`; the T-00004 security correction
  supersedes that serializable secret-bearing seam with the synchronous `AuthenticationService::activate()`.
- `UserActivated` remains the safe post-commit outcome. The service hashes the plain password, consumes the
  matching grant, creates the first refresh session and token set, and activates the identity in one Unit of Work.
- Focused PHPUnit passed with 11 tests and 49 assertions. `./bin/planning-check` and `./bin/build` passed, with
  the full build enforcing exact executable coverage.
- Coordinate evidence is retained under `.runs/2026-08-19-t-00003-activate-invited-account/` and is intentionally
  ignored from version control.
