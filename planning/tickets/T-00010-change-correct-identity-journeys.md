---
id: T-00010
title: Change and correct identity journeys
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00002
  - T-00007
  - T-00009
branch: feature/t-00010-change-correct-identity-journeys
---

# Change and correct identity journeys

## Outcome

An active user can reserve and confirm a new email address without displacing the old one prematurely; authorized
assistance follows the same confirmation journey, and a pending invitation can be corrected atomically.

## Acceptance criteria

- [ ] Email change reserves the destination until confirmed; cancellation and expiry release only that reservation.
- [ ] Confirmation uses an unrelated, hashed, expiring, single-use grant and revokes sessions before requiring new login.
- [ ] Super-administrator initiation or cancellation cannot bypass mailbox confirmation and is durably audited.
- [ ] Pending-invitation correction replaces its prior address, grant, and delivery atomically.
- [ ] Tests prove uniqueness, confirmation, cancellation, expiry, authorization, and predecessor rejection.

## Exclusions

No mailbox implementation, HTTP confirmation page, persistence unique-index implementation, or admin UI.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
