---
id: T-00018
prd: PRD-00001
title: Publish successful security-email delivery events
status: done
blocked_by:
---

# Publish successful security-email delivery events

## Outcome

Consumers receive explicit, serializable Domain events after a user invitation or email-change message has been
successfully invoked and its confirmed delivery state has committed durably.

## Acceptance Criteria

- [x] `UserInvitationDelivered` lives under the `ActivationGrant` aggregate boundary and identifies the actor, User,
  and activation-delivery generation associated with the confirmed invitation delivery.
- [x] `EmailChangeDelivered` lives under the `EmailChangeGrant` aggregate boundary and identifies the actor, User,
  and email-change-delivery generation associated with the confirmed email-change delivery.
- [x] Both events implement the public Fight Common Event contract as immutable, canonically serializable Domain
  messages with named accessors, round-trip coverage, and rejection of missing required data.
- [x] Each delivery handler publishes its success event only after the confirmed delivery state and required audit
  evidence commit successfully.
- [x] Transport failure, stale or mismatched work, rejected delivery transitions, and failed commits publish no
  delivery-success event; existing `CommandFailedEvent` dispatch and unchanged rethrow behavior remain intact.
- [x] Tests prove invocation, persistence commit, and success-event ordering for both journeys while retaining exact
  executable statement coverage.

## Scope

### Out of Scope

No new delivery command, retry or resend semantic change, mail transport, template, worker, queue, persistence
adapter, schema, migration, HTTP or OpenAPI contract, generated client, React application, or other UI work.

## Verification

- Focused activation and email-change delivery Event and CommandHandler tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- Added aggregate-scoped `UserInvitationDelivered` and `EmailChangeDelivered` Domain events with canonical
  serialization, named accessors, and missing-data rejection.
- Delivery handlers now construct each success event inside their single Unit of Work and dispatch it only after
  confirmed delivery state and required audit evidence commit successfully.
- Focused activation and email-change handler tests prove invocation, durable confirmation and audit evidence,
  post-commit success publication, serialization, and absence of a success event for failed invocation, stale work,
  compare-and-set loss, audit failure, and commit failure.
- `./bin/planning-check` passed with 19 records and 2 active after closure.
- `./bin/build` passed with 510 tests, 3,625 assertions, and exact 3,792/3,792 statement coverage after final
  refinements.
- Final independent Standards and Spec reviews reported no findings.
