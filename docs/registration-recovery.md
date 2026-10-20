# Administrator pending-registration recovery (v0.7.0)

[TASK-00075](../planning/tasks/00075-TASK.md) adds distinct `RecoverPendingRegistration` /
`RecoverPendingRegistrationHandler` and post-commit `PendingRegistrationRecovered`, released in
[v0.7.0](release-0.7.0.md). This is not anonymous registration, private-invitation correction,
an expired-token retry or consumer enablement. It keeps the existing pending User and **exact assigned Role set**.
Changed registration defaults are never consulted. Package publication does not establish consumer qualification.

## Admission and inspection

The immutable command's exact fields are `actor_id`, `user_id`, `expected_activation_delivery_id`, `email`.
Construct it from typed `UserId`, `UserId`, `ActivationDeliveryId`, `EmailAddress` values in that order. `fromArray()`
rejects missing, null, non-string, invalid and extra data, including Roles, credentials and origin. Possession of any
of these fields grants no authority. Both variants use this command:

- A different canonical email requests wrong-address correction, before or after activation expiry.
- The same canonical email requests fresh activation **only at exact expiry or later**. Case-only changes are the
  same destination: no email rewrite, timestamp or User revision change occurs. Before expiry this rejects, not resends.

Protect **every** HTTP, CLI, queue and other command/query dispatch path in the consumer. Additionally implement the
mandatory Application `User\Service\RegistrationRecoveryAuthorization::assertCanRecoverRegistration(actorId, userId)`.
The handler calls it inside its transaction, before protected repository reads. Bind the claimed actor to trusted
current authenticated administrator context, reject actor substitution and check permission for the **exact target**.
Keep that authority valid through transaction completion under the consumer's authorization fence. A permissive stub,
caller-supplied actor, route admission alone or cached UI permission is not production authorization. Throw
`RegistrationRecoveryAuthorizationException` for denied actor/target admission. Consumers own actual policy and wiring.

Use existing consumer-protected `GetUserById` and `FindInvitationDeliveryStatus` for operator inspection. The latter's
current `InvitationDeliveryStatusView` now requires/exposes typed `getActivationDeliveryId()` and serialized
`activation_delivery_id`, alongside User, delivery status and expiry. This non-secret generation identity is necessary
for the command expectation and uncertain-commit resolution; it does not authorize recovery or reveal credentials.
No new query, public address-existence check or availability preflight is added. These reads are advisory: they neither
reserve state nor prove final eligibility. The handler revalidates current origin, User, generation and destination.

## Composition and atomic persistence

Construct `RecoverPendingRegistrationHandler` with, in order: Domain `UserRepository`, `ActivationGrantRepository`,
Application `RegistrationRecoveryAuthorization`, Domain `AuditEvidenceRepository`, Common `TransactionalUnitOfWork`,
Application `ActivationCredentialGenerator`, `InvitationDeliveryCipher`, Timing `Clock`, Common `EventDispatcher`.
Register it through `commandRegistration()`. No Role repository or default-Role configuration belongs here.

Within one transaction:

1. Authorize the exact actor/target. Load authoritative current User and latest activation grant.
2. Take one fresh clock sample after those reads. This is admission time, not a commit/completion deadline.
3. Require issued, unconsumed/unrevoked **registration-origin** authority, exact expected delivery generation,
   consistent User/delivery ownership, expiry and canonical destination. Require pending User state without a
   password or unrelated pending email reservation. Absent, active, disabled, deleted, private, stale, mismatched
   or inconsistent work rejects. Same-address requests also require expiry at or before that admission instant.
4. Clone the User and apply `recoverPendingRegistrationEmail()`. Mandatory
   `UserRepository::replacePendingRegistration(expected, replacement)` compares the complete stored expected User
   and admits only that exact result: a no-op for same email, or corrected email with one canonical revision/update.
   Reject another canonical claim or live email-change reservation. Preserve ID, creation, Roles and their revision,
   account state, password, authentication authority and unrelated reservation state.
5. Even for the no-op case, hold the shared User identity/claim fence **through transaction completion**. Every User
   writer, including activation, lifecycle, Role, email/reservation and canonical-claim writers, must participate.
   A snapshot read alone is insufficient. Release fences on rollback and commit; never clear another reservation.
6. Generate/hash fresh one-time activation and encrypt delivery material. Complete-state
   `ActivationGrantRepository::replaceWithSuccessor()` revokes the predecessor, destroys its material and inserts
   a pristine registration-origin successor with new grant/delivery IDs, historically unused digest and seven-day
   expiry from admission. All grant writers share their per-User generation fence through completion, including
   claim/outcome, consumption, resend and recovery. Compare full origin/authority/delivery state, not ID/revision only.
7. Record audit source as the admitted actor, action `user.pending_registration_recovered`, subject as the retained
   User ID, with empty context. Commit User, predecessor, successor delivery and audit together, or roll back all.

Consumers implement this current Domain repository contract directly on the actual shared connection. There is no
optional capability/default implementation, production Adapter, prior-data reader, migration or compatibility path;
[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md) applies. Physical locking, serialization failures,
deadlock retries and ORM refresh behavior need actual consumer qualification.

## Publication, mail and uncertain outcomes

After confirmed commit, the void handler publishes `PendingRegistrationRecovered` with exactly actor, retained User,
previous delivery ID, successor delivery ID and committed email. It contains no token, ciphertext or Role authority.
The opt-in `Fight.AccessControl.RecoverPendingRegistration` and `Fight.AccessControl.PendingRegistrationRecovered`
OpenAPI schemas match these messages. There is no HTTP endpoint or package generic success response.

Register `InvitationDeliverySubscriber` for `PendingRegistrationRecovered`; its new method dispatches
`DeliverUserInvitation` with the admitted actor's string provenance and successor identity through the normal bus.
The worker claims durably, then invokes the consumer provider outside every transaction with stored
`ActivationOrigin::REGISTRATION`, purpose `activation` and stable successor delivery ID as idempotency identity.
Select a consumer-owned registration template from this **stored origin**, never the command/event name or route.
Keep the private-invitation template separately configurable. Provider acceptance leaves the same User pending;
existing one-time password-setting activation activates it with all its Roles, and replay rejects.

Delivered, permanent-failure and expiry-cleaned predecessor **delivery** do not mean activation; if authority is still
issued and the User is eligible, expired same-email reissue creates a fresh generation. Resend retains registration
origin. Retry/reclaim/restart retain the delivery ID **within** a generation; fresh recovery/resend gets a new ID.
An obsolete unclaimed worker cannot invoke as current work. Already admitted provider calls can finish externally
after replacement, but their delayed delivered/retry/permanent outcomes cannot mutate the successor or revive a token.
This remains at-least-once delivery, not exactly-once mail or guaranteed event publication.

Every handler failure attempts `CommandFailedEvent` with the original safe command and constant
`Pending registration recovery failed.` text, then rethrows the **same original throwable**, even if failure
notification throws. Do not copy arbitrary storage, cipher, subscriber or provider diagnostics into evidence.
Consumers must sanitize the rethrown throwable at response/log boundaries and protect email/identity-bearing events.

- Pre-commit write, generation, cipher, audit or commit failure rolls back all recovery effects. The predecessor
  remains authoritative at its previous address with every original Role.
- Post-commit event/subscriber/provider failure does not undo recovery or justify a second issuance. Both outer
  publishers may fail; protected scheduler discovery still recovers committed due work without command replay.
- A lost response or indeterminate commit is **not inferred rollback**. Reconnect to authoritative storage, inspect
  the same User/Roles and latest delivery ID/status/expiry, and compare the expected predecessor. In the modeled
  committed outcome the successor persists; replaying the old expectation rejects without another success fact.
  In the modeled rolled-back outcome the predecessor persists unchanged and a newly authorized request can proceed.
  Unavailable/non-authoritative reads cannot establish either result; stop unsafe effects until resolved. If later
  writers intervened, use protected authoritative history/audit rather than infer which request committed.

`CorrectPendingInvitation` still rejects registration. A deleted User restored to pending begins a **new private
invitation**, including after earlier registration. Current lifecycle policy does not permit deleting a pending User:
there is no new pending-delete/cancellation transition. A legitimate activation → deletion → restoration journey
can win against a stale recovery and must retain private origin and the original Roles.

## Package evidence and remaining consumer qualification

| TASK criterion | Executable package evidence |
| --- | --- |
| C1 | `RecoverPendingRegistrationHandlerTest`: exact actor/target transactional admission, strict DTO validation/round trips/registration; existing protected status generation output tests |
| C2–C4 | Handler tests for both variants, exact/pre/post expiry, all nonpending states, missing/private/terminal/stale/mismatched authority, inconsistent pending password/reservation, all-state canonical claims/live reservations and full rollback; journey tests for terminal delivery, private guard, duplicate/default-Role non-takeover |
| C5 | `RegistrationRecoveryRaceTest`: Fiber-paused pre-write recovery/resend, correction/another recovery and activation schedules in both winner orders; reservation winner orders; held User/grant fences through commit/rollback; delayed delivered/retry/permanent outcomes; legal lifecycle orders with stale recovery versus activation/delete/restore |
| C6 | `RegistrationRecoveryJourneyTest`: both variants through subscriber/Common bus/provider/single-use activation; both uncertain outcomes, response loss/double publication failure, scheduler restart and provider retry/resend; generator/cipher failure tests assert original throwable and redacted attempted failure evidence |
| C7–C8 | Generated recovery/status-schema tests, focused/full canonical gate and planning/documentation checks; TASK and ignored Work handoff own exact counts, logs/exits and tested-content mapping |

These are controlled Domain/Application package-port proofs. Separate transaction contexts share modeled persisted
state and reject conflicts; Fiber pauses occur only before writes. Held-fence tests challenge independent writers
through completion. Restart reconstitutes grants and reads retained User state; it is not an operating-system restart.
Pending deletion remains rejected in either order; tests do not invent an unsupported competing transition.

**Unexecuted real-consumer obligations:** actual administrator authentication/target-policy and authority fencing;
shared DB connection, canonical uniqueness/reservation locks and complete-state CAS in parallel SQL sessions; rollback,
uncertain commit and process restart; cipher/key durability; provider idempotency; actual scheduler; independently
configurable registration/private templates and origin routing; every exposed dispatch/query path, public enumeration,
throttling/challenges, abuse logging and response sanitization. Package evidence does not qualify these boundaries,
enable registration, authorize consumer integration, or grant release/deployment permission. Independent Review and
applicable nonvisual QA remain separate from the Engineer's implementation evidence.
