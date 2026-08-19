---
id: T-00002
title: Recover and resend activation delivery
status: done
parent: PRD-00001
blocked_by:
  - T-00001
branch: feature/t-00002-recover-resend-activation-delivery
---

# Recover and resend activation delivery

## Outcome

An inviter can discover failed activation delivery and retry it without duplicating an identity; a resend replaces
the prior activation grant so only the newest message can activate the account.

## Acceptance criteria

- [x] Delivery work is queryable by a safe operational status and can be retried through an invocation-neutral port.
- [x] Resend revokes the predecessor activation grant and stages new recoverable delivery work.
- [x] Confirmed delivery and terminal expiry destroy the recoverable raw credential.
- [x] Tests prove retry, predecessor rejection, and failure recovery behavior.

## Exclusions

No queue worker, mail provider, template, or encryption implementation.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Evidence

- `./bin/planning-check` passed with 2 records and 2 active.
- `./bin/build` passed with 73 tests, 378 assertions, and 267/267 exact statement coverage.
- Safe delivery status, retry failure recovery, and atomic resend are covered through framework-neutral Domain and Application seams; no production Adapter, mail, queue, template, or encryption implementation was introduced.
