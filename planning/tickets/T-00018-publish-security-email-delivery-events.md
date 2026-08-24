---
id: T-00018
title: Publish successful security-email delivery events
status: ready-for-agent
parent: PRD-00001
blocked_by: []
branch: feature/t-00018-publish-security-email-delivery-events
---

# Publish successful security-email delivery events

## Outcome

Consumers receive explicit, serializable Domain events after a user invitation or email-change message has been
successfully invoked and its confirmed delivery state has committed durably.

## Acceptance criteria

- [ ] `UserInvitationDelivered` lives under the `ActivationGrant` aggregate boundary and identifies the actor, User,
  and activation-delivery generation associated with the confirmed invitation delivery.
- [ ] `EmailChangeDelivered` lives under the `EmailChangeGrant` aggregate boundary and identifies the actor, User,
  and email-change-delivery generation associated with the confirmed email-change delivery.
- [ ] Both events implement the public Fight Common Event contract as immutable, canonically serializable Domain
  messages with named accessors, round-trip coverage, and rejection of missing required data.
- [ ] Each delivery handler publishes its success event only after the confirmed delivery state and required audit
  evidence commit successfully.
- [ ] Transport failure, stale or mismatched work, rejected delivery transitions, and failed commits publish no
  delivery-success event; existing `CommandFailedEvent` dispatch and unchanged rethrow behavior remain intact.
- [ ] Tests prove invocation, persistence commit, and success-event ordering for both journeys while retaining exact
  executable statement coverage.

## Exclusions

No new delivery command, retry or resend semantic change, mail transport, template, worker, queue, persistence
adapter, schema, migration, HTTP or OpenAPI contract, generated client, React application, or other UI work.

## Verification

- Focused activation and email-change delivery Event and CommandHandler tests
- `./bin/planning-check`
- `./bin/build`
