# Make package delivery state the authoritative durable queue

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John approved the package-owned queue and all-three-family scope on 2026-09-24.
**Map:** [Recoverable credential delivery](../recoverable-credential-delivery-map.md)
**Depends on:** —

## Question

What is the authoritative durable-work boundary for AccessControl-owned credential delivery, and which lifecycle
families must use it in the first stable package release?

## Must decide

- Whether a consumer-owned registration/outbox capability or package delivery state owns recovery authority.
- How claim, provider invocation, outcome persistence, idempotency, lease recovery, and secret material cross the
  Domain/Application-to-consumer boundary.
- Whether email change joins invitation and password reset in the release even while a consumer may defer its own
  provider wiring.

## Required evidence

- The published `v0.2.0` tag resolves to `c986b488ef8490c16c7a69e4675b5348f8d6dbf5`, the consumer's locked
  source reference.
- `InvitePendingUserHandler`, `RestoreUserHandler`, `CorrectPendingInvitationHandler`, and
  `ResendInvitationDeliveryHandler` commit grant-owned encrypted work before their post-commit event.
- `DeliverUserInvitationHandler` and `DeliverEmailChangeHandler` currently invoke their provider ports inside
  `UnitOfWork::commitTransactional()`.
- `RequestPasswordResetHandler` commits encrypted work and publishes `PasswordResetRequested` but supplies no
  package-owned provider, claim, retry, or discovery path.
- Existing repositories provide per-generation identities and compare-and-set replacement, but no due-work discovery,
  claim lease, claim token, retry timing, or expected-outcome operation.

## Current implementation timeline

| Path | Durable originating transaction | Post-commit coordination | Present recovery/effect gap |
| --- | --- | --- | --- |
| Initial invitation | Pending User, activation grant, encrypted delivery, and `user.invited` audit evidence | `UserInvited` → subscriber → `DeliverUserInvitation` | Crash between commit and dispatch loses immediate scheduling; the later delivery handler invokes provider inside its claim/outcome transaction. |
| Restored pending User | Restored User, successor activation grant, encrypted delivery, and `user.restored` audit evidence | `UserRestored` → subscriber → `DeliverUserInvitation` when a delivery ID exists | Same post-commit scheduling gap and in-transaction provider call. |
| Corrected pending invitation | Corrected User email, revoked predecessor, successor activation grant/delivery, and `user.pending_invitation_corrected` audit evidence | `PendingInvitationCorrected` has no present invitation-subscriber route | No immediate dispatch at all; delivery requires package discovery. |
| Invitation resend/retry | Resend replaces grant and records `user.invitation_delivery.resent`; retry transitions failed work to pending and records retry audit | `InvitationDeliveryResent` or `InvitationDeliveryRetryRequested` → subscriber → `DeliverUserInvitation` | Same in-transaction provider call; retry is manual/event-driven rather than deterministic due-work recovery. |
| Password reset | Eligible active User receives fresh reset grant/delivery; predecessor is terminalized when needed; `user.password_reset_requested` audit evidence persists | `PasswordResetRequested` only | No package subscriber, provider port, claim, failure/retry state, or discovery mechanism. Confirm/expire only accept an externally supplied delivery ID. |
| Email change | Reservation, grant, encrypted delivery, and applicable administrative audit evidence | `EmailChangeRequested` → subscriber → `DeliverEmailChange` | Crash can lose immediate dispatch; current delivery handler invokes provider inside its transaction. Cancellation and expiry invalidate material/reservation. |

## Crash and external-effect evidence

- After an originating commit but before post-commit event dispatch, durable delivery exists but current consumer
  coordination can be absent. Only due-work discovery closes that window.
- In the present invitation/email-change handlers, claim, provider call, audit, and success/failure state share one
  transaction. A provider acceptance followed by process crash or failed commit leaves no durable confirmation; retry
  can duplicate the external effect. Conversely a provider delay holds database work open.
- Current repository compare-and-set checks prevent stale generations from overwriting a replacement, but not a
  provider call made by a claimant whose transaction has not yet committed. A committed lease and matching expected
  outcome are required to fence state; provider idempotency is required to make duplicate external attempts safe.

## Resolution boundary

This decision fixes durable-work ownership and the common lifecycle model. It does not choose retry cadence or
attempt budget, exact PHP class names, consumer provider implementation, database schema, or release execution.

## Resolution

AccessControl-owned delivery state is the authoritative durable queue. Alternative A, a consumer outbox-registration
capability, could share the originating transaction but duplicates the grant-owned delivery state machine and makes
recovery depend on consumer registration/reconciliation. It is excluded. No better compatible alternative is needed.

The first `v0.3.0` package release applies one model to invitation, password reset, and email change:

1. The originating mutation atomically commits its aggregate, encrypted delivery material, and required audit
   evidence through the consumer's one shared `UnitOfWork`.
2. A package handler claims one exact delivery generation in a short transaction, persisted with a unique claim token,
   lease deadline, attempt metadata, and compare-and-set aggregate revision.
3. After that transaction commits, the handler materializes package-approved encrypted credential material only for
   the provider invocation and calls a provider-neutral consumer capability with the delivery-generation ID as its
   stable idempotency key.
4. A separate expected-state transaction accepts only the matching unexpired claim token and revision, then records
   delivered, retryable, or permanent outcome and its required secret-free audit evidence. A stale claimant cannot
   overwrite newer state.

Due-work discovery is secret-free and deterministic. It includes pending, due-retry, and expired-lease work in a
stable order; consumers schedule it and dispatch the same direct package handlers. Post-commit events and existing
subscribers may still start immediate work but never supply recovery authority. A crash after provider acceptance
and before outcome commit can repeat the provider call after lease recovery, so the contract explicitly promises
at-least-once—not exactly-once—and requires a provider adapter to honor the stable idempotency identity.

Current ciphertext-exposing invoker inputs are replaced. Raw credentials, hashes, ciphertext, provider secrets,
credential-bearing URLs, and arbitrary provider errors are not serializable work, event, query, audit, or ordinary
diagnostic data. Provider adapters return typed delivered/retryable/permanent outcomes; an unexpected throwable is
recorded only as a safe retryable classification, never by parsing or persisting its message.

Email change is included to avoid incompatible public delivery models. Fight Agent OS may defer only its own
email-change provider composition, not the package lifecycle contract.

## Accepted transaction timeline

| Phase | Transaction and effect rule |
| --- | --- |
| Originating mutation | One consumer-supplied `UnitOfWork` atomically persists package state, encrypted delivery material, and required audit evidence. It commits before any event dispatch or provider invocation. |
| Discovery and claim | A consumer scheduler reads a secret-free deterministic due-work page and dispatches an exact package handler. That handler claims one revision with token and lease in a short transaction, then commits. A CAS loser does not invoke a provider. |
| Provider invocation | With no originating, claim, or outcome transaction open, the package decrypts only the claimed material, calls the provider with stable delivery ID, and discards plaintext after the call. |
| Delivered outcome | A separate transaction requires matching delivery ID, claim token, and revision; it records confirmed delivery/audit evidence and destroys ciphertext. |
| Retryable outcome | A separate expected-state transaction retains ciphertext, records a safe retryable classification and package-calculated next due time, then commits. |
| Permanent outcome | A separate expected-state transaction records safe terminal/audit status, destroys ciphertext, and prevents automatic retry. |
| Provider accepted, outcome absent | Crash or outcome-commit failure leaves an eventually recoverable claim. Lease expiry makes it due again; the same delivery ID repeats, so the provider adapter must deduplicate. This is at-least-once. |
| Abandoned claim | Discovery recognizes an expired lease. A later worker atomically reclaims the current revision; an earlier claimant's outcome is rejected. |
