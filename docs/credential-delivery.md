# Current User credential-delivery contract

Invitation, password-reset and email-change grants own recoverable, provider-neutral delivery. Only this current
pre-v1 contract is supported under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). There is no
historical signature, row conversion or migration/backfill obligation. Consumer adapters and runtime remain separate.

## Composition and persistence

Bind the three purpose-specific cipher capabilities (encryption and decryption), one `CredentialDeliveryProvider`,
Domain grant repositories, audit and User repositories to the package's shared `TransactionalUnitOfWork` connection.
Originating handlers atomically persist grant authority, encrypted delivery and audit before post-commit publication.
Do not independently commit one repository or bind participants to different transaction connections.

Persist the complete delivery state: immutable delivery ID, owning User, canonical email, encrypted material, expiry,
explicit due time, status, optional claim token/claimed time/lease, attempt count, last attempt/outcome time and safe
failure classification. Persist the aggregate revision too. Hydration restores exact state; unsupported or corrupt
state must not become new issuance. `EncryptedCredentialMaterial::reveal()` is for approved persistence and cipher
boundaries only, never diagnostics. Material and sensitive invocation objects reject serialization.

Every complete-state `replace()` compares the authoritative predecessor including the revision and every delivery
field. Stale or fabricated predecessors lose without mutation. `add()` and successor writes require pristine delivery
state and fresh generation IDs/digests. Revocation, consumption and other terminal transitions destroy recoverable
material without erasing state needed to reject stale generations.

## Activation origin (Unreleased)

`ActivationOrigin` is a required, non-sensitive business fact on `ActivationGrant`, shared by its exact owned
activation delivery generation. Its only serialized values are `private_invitation` and `registration`.
`activation` remains the credential purpose for both; origin is not authorization, template content, historical
User classification or evidence of activation. Reset and email-change work have no activation origin.

Current consumer composition must change directly:

- `ActivationGrant::issue()` and its protected hydration constructor require `ActivationOrigin` as the **first**
  argument. Trusted issuance code chooses it; no delivery, retry, resend or correction Command accepts origin.
  `InvitePendingUserHandler` explicitly issues `PRIVATE_INVITATION`; `RegisterUserHandler` explicitly issues
  `REGISTRATION` in its atomic pending-User/default-Role creation. See [registration composition](user-registration.md).
- Persist the grant's origin with authority, encrypted delivery and audit on the shared transaction connection.
  Hydrate with `ActivationOrigin::from($storedOrigin)` before constructing the grant. Missing/null/unknown values
  reject; do not use `tryFrom() ?? PRIVATE_INVITATION`, historical readers, row conversion or event-based inference.
  The standalone `ActivationDelivery` entity is not an independent issuance record: persist/hydrate its owning
  grant and required origin together, even after delivery material is destroyed.
- Complete-state repository comparisons include origin in **both** the expected predecessor and same-generation
  replacement, including terminal predecessors in `replaceWithSuccessor()`. Same ID/revision with a different
  origin is fabricated state, not a valid transition. Atomic successor creation may establish a new journey only
  through the authorized use case. Keep all existing ownership, digest, revision, claim and latest-generation fences.
- `CredentialDeliveryInvocation` requires a fifth argument, `?ActivationOrigin`: the claimed grant's stored origin
  for activation and explicit `null` for password reset/email change. The constructor rejects a missing activation
  origin or an origin on another purpose. Providers read `getActivationOrigin()` and exhaustively choose their own
  private-invitation versus registration template; they must not select from an event, caller route or `activation`
  purpose alone. No package template, template service or installation-enable switch is supplied. The invocation
  remains short-lived, redacted and non-serializable; delivery ID, not origin, remains the idempotency identity.

Claims, typed outcomes, manual retry, scheduler discovery and lease takeover preserve the generation's stored origin.
A fresh worker reconstructs it from authoritative storage, not the secret-free due/status DTOs. The protected
latest invitation status additionally exposes `activation_delivery_id` for administrator recovery expectations; Immediate direct dispatch and event-triggered dispatch use that same worker. Both origins are delivered
outside transactions; provider success still does not activate the pending User.

`ResendInvitationDelivery` preserves the latest issued generation's origin in its fresh successor, including after
expiry; it cannot reclassify one origin as the other. `CorrectPendingInvitation` rejects registration-origin grants
before User mutation; valid private correction creates another private invitation. Administrator restore of a
**deleted** User to pending creates a new private invitation regardless of earlier registration origin. It does not
recover a pending registration; use distinct authorized
[administrator registration recovery](registration-recovery.md) for that pending identity.

Failed writes/commit roll back the coupled changes. A confirmed or uncertain committed claim/outcome is resolved
from persisted state after restart; unacknowledged provider acceptance can still repeat with the same delivery ID.
Post-commit publication failure rethrows without undoing committed work; scheduler recovery does not require that
publication. An already admitted old call can finish externally after replacement, but its stale outcome cannot
mutate the successor, and an obsolete unclaimed command cannot invoke as current work. No exactly-once mail or
reliable-event publication guarantee is introduced. Continue sanitizing storage exceptions at consumer boundaries.

`ActivationOriginTest`, `ActivationOriginRecoveryTest` and the updated issuer/correction/restore tests cover these
package-port outcomes. `ActivationOriginConformance` is a consumer-bindable repository suite: subclass it, supply an
isolated `repository()` and a `restart(repository)` that closes/reopens persistence and hydrates every field without
reissuing grants. Arrange the adapter's real transaction/commit boundary around writes as required by its composition.
The in-memory binding reconstructs grants from current enum values and cloned delivery snapshots; it models restart,
not a real database or process. Bind and extend the failure schedules against the actual shared connection.

This is a **breaking pre-v1 minor** current API/persistence change, not a v0.6.x patch or an old-data migration route.
The opt-in `Fight.AccessControl.ActivationOrigin` OpenAPI value component describes the enum, not a serializable
provider invocation or a new caller-selected field. Consumers separately qualify real SQL CAS/atomicity, provider
idempotency, keys, actual private/registration template configuration, scheduler restart, public protections and
transport. The [registration journey tests](user-registration.md#package-evidence-map) separately exercise its command,
all default Roles and same-User activation at package ports; neither slice qualifies consumer adoption, release or deployment.

## Explicit claim and outcome transitions

The aggregate `claimDelivery(token, claimedAt, leaseUntil)` and delivery `claim()` require all three arguments.
`confirmDelivery(token, occurredAt)`/`confirm()` require exact claim identity and occurrence time.
`failDelivery(token, occurredAt, failure)`/`fail()` also require a typed failure; permanent failure has its own method.
There is no inferred claim token, timestamp, lease, failure, or epoch-based due-time default. Delivery factories require
an explicit due time. `hasRecoverableMaterial()` is the one shared material predicate.

`ConfirmPasswordResetDelivery` and its handler/schema are removed: consumer transport callbacks cannot bypass the
package-owned committed claim. `PasswordResetDeliveryConfirmed` remains the actual worker's post-commit fact.
All three delivery workers use the same phases:

1. Commit an exact generation claim in a short transaction.
2. Decrypt and call the provider outside every transaction using the immutable generation ID as idempotency identity.
3. Commit one typed delivered/retryable/permanent outcome only while the same claim is live and the complete
   predecessor state still matches. Wrong tokens, outcomes before claim time and outcomes at/after lease expiry reject.

Package worker leases are five minutes, capped at delivery expiry. Retry backoff starts at 60 seconds, doubles with
attempt count and caps at 3600 seconds; a retry at/after expiry terminalizes instead. These are current operational
policies, not historical argument-inference defaults. Delivered, permanent-failure, expired and invalidated state
contains no recoverable material. Unknown provider exceptions become the safe `unexpected_provider` classification,
not persisted vendor diagnostics.

## Registration and recovery

For optional **User self-registration**, use distinct `RegisterUserHandler` with trusted nonempty RoleName defaults and
mandatory creation-time Role fencing, as described in the [registration guide](user-registration.md). Its `UserRegistered`
fact is routed by `InvitationDeliverySubscriber` using fixed `registration` source provenance, not an authenticated actor.
The worker uses stored origin and the normal activation purpose. Registration/default Roles/grant/delivery/audit commit
before any invocation. Pending User and Roles survive publication/provider faults; delivery does not activate the User.
For administrator correction or expired-same-email fresh activation, register `RecoverPendingRegistrationHandler`
and its exact-target `RegistrationRecoveryAuthorization` port. Its `PendingRegistrationRecovered` fact is also routed
by `InvitationDeliverySubscriber` after commit, retaining stored registration origin. Follow the
[recovery guide](registration-recovery.md) for mandatory User no-op/correction fencing, expected-generation validation,
rollback and uncertain-commit resolution. The table below lists delivery-worker registrations.

| Message | Handler |
| --- | --- |
| `DeliverUserInvitation` | `DeliverUserInvitationHandler` |
| `DeliverPasswordReset` | `DeliverPasswordResetHandler` |
| `DeliverEmailChange` | `DeliverEmailChangeHandler` |
| `FindDueCredentialDeliveries` | `FindDueCredentialDeliveriesHandler` |
| `FindExpiredCredentialDeliveries` | `FindExpiredCredentialDeliveriesHandler` |
| `ExpireInvitationDelivery` | `ExpireInvitationDeliveryHandler` |
| `ExpirePasswordResetDelivery` | `ExpirePasswordResetDeliveryHandler` |
| `ExpireEmailChange` | `ExpireEmailChangeHandler` |
| `FindCredentialDeliveryStatus` | `FindCredentialDeliveryStatusHandler` |

Optional invitation/password-reset/email-change subscribers provide immediate post-commit dispatch, not guaranteed
recovery. Schedule `FindDueCredentialDeliveries` with current time and a positive bound. Dispatch the returned purpose,
User ID and delivery ID through the matching direct command using the consumer's trusted audit identity. Never derive
credentials or destinations from scheduler data. Authoritative latest generations only are returned, ordered by
eligibility time then delivery ID: pending/due retries at due time and abandoned claims at lease expiry.

With synchronous dispatch, wait for the page's attempts to finish before rediscovery. With asynchronous queues,
enqueue at most one bounded page per cycle; do not loop on still-due rows before claims can commit. Concurrent workers
may discover the same row; only a committed claim winner invokes the provider. Infrastructure schedules package
messages, not another claim/backoff/outcome state machine.

A provider acceptance followed by lost outcome commit may repeat invocation with the same delivery ID. This is
at-least-once invocation, not exactly-once effect. The provider adapter must make repeated identities converge and
return `DELIVERED`, `RETRYABLE_FAILURE` or `PERMANENT_FAILURE`. Replacement generations have fresh identities.
Secret-free status queries do not expose claim tokens, credentials, hashes, ciphertext or provider errors.

For registration and private invitation alike, `DeliverUserInvitationHandler` attempts `CommandFailedEvent` with the
original safe delivery command and constant `User invitation delivery failed.` text, not arbitrary storage/subscriber
diagnostics. Failure notification is best effort: even if it throws, the same original operation throwable reaches the
caller. Confirmed registration/claim/outcome commits are not undone by later publication failure. Consumers must still
sanitize rethrown faults and secure event/log access. This Unreleased worker correction does not change the separate
manual-retry handler's diagnostic behavior described below.

## Manual invitation retry admission (v0.6.0)

`RetryInvitationDelivery(actorId, userId)` targets the latest invitation generation at execution, not an old delivery
reference. Protect the entry point with consumer authorization; actor provenance does not grant permission.
`RetryInvitationDeliveryHandler` requires these constructor arguments, in order:

1. `ActivationGrantRepository`
2. `AuditEvidenceRepository`
3. `TransactionalUnitOfWork`
4. `Fight\AccessControl\Application\AccessControl\Timing\Service\Clock`
5. Common `EventDispatcher`

Update consumer DI/factories directly. There is no optional/system-clock fallback or historical constructor. This is
a **breaking pre-v1 minor** composition change selected for [v0.6.0](release-0.6.0.md), not a drop-in v0.5.x patch.
Version selection and this guide do not establish publication.
For that v0.6.0 retry repair, Command/event payloads, repository signatures, persisted state and provider idempotency
formats were unchanged. The Unreleased activation-origin contract above separately changes issuance/hydration and
provider composition plus required persisted state.

Inside the shared transaction, the handler reads the latest grant, then takes one fresh clock sample before mutation,
complete-state CAS and audit. Only still-issued authority with `now < expiresAt` and material-bearing `RETRY_PENDING`
delivery may retry. Exact expiry, including fractional/timezone-equivalent equality, rejects. Missing, consumed,
revoked or ineligible delivery also rejects with public `ActivationDeliveryNotRetryableException` and constant text
`The activation delivery work is no longer retryable.` A lost CAS retains the same class and constant concurrent-change
message. Map the exception class, not parsed text. Normal `CommandFailedEvent` evidence may publish the safe command
and rejection text; notification failure cannot mask the original throwable. Arbitrary infrastructure diagnostics are
not newly redacted by this repair: sanitize rethrown faults and event/log boundaries in the consumer.

Rejected expiry performs no retry transition, replace, revision/due/history/material change, retry audit, retry-request
fact, decryption or provider invocation. It does **not** clean up the old row or implicitly resend. A successful retry
keeps grant/User/digest/expiry, destination, material, delivery ID and attempt/outcome/failure history. Only the existing
retry transition, due-time reset to the previous attempt and one revision advance occur. Grant and retry audit commit
together, then `InvitationDeliveryRetryRequested` can invoke the optional subscriber. Publication failure cannot roll
back that confirmed commit and is not converted into a success/warning result.

This is **admission-time**, not wall-clock-at-commit or completion-before-expiry enforcement. A transactional wait/read
that crosses expiry rejects using the fresh post-read sample. A sample before expiry may be followed by expiry before
commit or subscriber claim: the existing worker independently rejects expired claims, while the committed retry
state/audit survives. Scheduler recovery, committed claims, backoff, leases and at-least-once provider invocation remain
unchanged. Manual retry admission differs from a worker retryable outcome whose next backoff reaches expiry (which
terminalizes delivery), and from the separate expiry-cleanup commands below.

For expired but still-issued authority, explicit authorized `ResendInvitationDelivery` revokes the predecessor and
creates a fresh credential/grant/delivery ID and expiry. It is fresh authority, not a retry or TTL extension; consumed
and revoked authority remain ineligible for resend. A new retry command can target an eligible latest successor, but
stale predecessor CAS/worker references cannot revive the obsolete generation.

## Offline expiry cleanup (v0.5.0)

Due discovery deliberately excludes exact/post-expiry work. Schedule the separate
[expired-work query and direct cleanup commands](credential-expiry.md) before the unchanged due-work path.
Invitation/reset expire recoverable delivery; email expires issued authority and its exact bound User reservation,
including finished delivery and inactive/restored accounts. Expiry needs no keys, decryption or providers. The three
repositories require bounded `findExpired()` and email grants persist the explicit reservation-revision binding supplied
by `RequestEmailChangeHandler`. Read the guide for atomicity, no-progress scheduling, restart and real-consumer obligations.

## Verification and limits

`ActivationDeliveryLifecycleTest`, the three grant suites and `CredentialDeliveryTransitionTest` prove explicit
claim fencing, stale/wrong/expired outcomes, retry/expiry and material destruction. The three delivery-handler suites
exercise actual package provider calls outside transactions, failed outcome commits and restart. Repository and
query-handler tests exercise modeled complete-state CAS and bounded due/status reads. `CredentialDeliveryComponentsTest`
checks generated schemas against runtime payloads in the default gate.

`RetryInvitationDeliveryHandlerTest` adds the saved regression, boundary/subscriber matrix, fractional equality,
post-read clock timing, same-identity/history, eligibility, deterministic writer schedules, persisted rollback and
post-commit-failure cases. `DeliverUserInvitationHandlerTest` retains lost-outcome-commit/repeated-delivery-ID proof;
these controlled package tests do not establish real PostgreSQL or provider deduplication conformance.

Run focused checks and `./bin/build`; consumer qualification separately requires actual database transactions,
concurrent writers, key handling, provider deduplication and scheduling. No migration, data reset, release, consumer
adoption or deployment is established by package verification.
