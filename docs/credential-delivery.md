# Current User credential-delivery contract

Invitation, password-reset and email-change grants own recoverable, provider-neutral delivery. Only this current
pre-v1 contract is supported under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). There is no
historical signature, row conversion or migration/backfill obligation. Consumer adapters and runtime remain separate.

## Request and worker contracts (Unreleased)

The eleven human request/delivery/retry/resend/cancellation/expiry handlers remain registered **void** Commands.
Consumers protect every worker and alternate entry point; actor IDs, stored origin and status are not grants.
Unknown/ineligible password-reset requests still complete without work or an existence signal. Ordinary stale,
early or terminal expiry dispatch remains a no-op; email expiry still releases only its exact reservation in every
supported account state. No Query/subscriber/admission policy or optional-audit feature is added here.

Use Common Application `StateConflict` for known request/state/CAS/claim/lease refusals and invitation retry/resend
refusals. Email request/cancellation use `ResourceNotFound` for an absent requested User and `PermissionDenied`
for the authorization port's declared administrative denial. Domain transition exceptions remain inward; handlers
translate only the specific transition/authorization call they own. They do not classify by diagnostic text or
wrap repository, clock, transaction, audit or publication faults as ordinary refusals. Broken lookup identity,
delivery correlation and unchanged-revision claim promises are internal faults, not missing work or retry success.
Update catches directly; no old exception facade or compatibility mode is supplied. These are breaking pre-v1 PHP
caller-contract changes, not a selected release/version.

Claims and outcomes still use separate transactions with complete-state CAS. Password reset validates current
User recovery authority before grant access in both phases. The owned callback results are locally annotated with
their actual Event/ID/nullable result; native UoW remains mixed, with no replacement wrapper or generic prerequisite.
At outcome, an unchanged revision must still describe the exact committed claim. Decryption and provider calls
remain outside transactions: their throwables deliberately become secret-free `UNEXPECTED_PROVIDER` retry outcomes.
Clock faults are internal; an expired claim is a conflict, not a newly manufactured provider failure. Real revision,
generation, authority or lease changes cannot acknowledge a successor or revive cancelled work. Late external mail
and lost acknowledgements retain at-least-once identity/recovery semantics, never exactly-once delivery.

Cancellation matches the grant's exact User/destination/reservation revision, not email equality alone. The reset
revocation helper validates its requested User before issued/terminal decisions and retains the caller-owned User
fence/transaction; its lifecycle/password/confirmation callers retain their own synchronous failure policies.
Confirmed state remains authoritative when post-commit publication throws. Failure notifications keep constant safe
text and cannot replace the primary fault; consumers must still sanitize rethrown diagnostics.

Provider outcomes remain the three-case enum. Internal attempt results have only four factory combinations:
delivered/null, retryable/RETRYABLE_PROVIDER, permanent/PERMANENT_PROVIDER and retryable/UNEXPECTED_PROVIDER.
Invocation purpose is restricted to activation/password_reset/email_change, with stored activation origin only for
activation. The opaque stable delivery ID is not newly restricted to UUID syntax. Invocation material stays redacted
and non-serializable (`__serialize(): never`). Cipher implementations must mark raw plaintext parameters sensitive;
PHP does not inherit the interface's parameter attribute. Keys, provider mechanisms and concrete trace/log safety
remain consumer-owned and require separate qualification.

`HumanCredentialContractTest`, `HumanCredentialCompositionTest` and the existing family/recovery/expiry/failure
suites exercise these boundaries. Static fixtures and focused level-8 analysis qualify the owned contracts, not
Common generic bus inference. Actual tagged minimum/ordinary routing and safe HTTP composition are recorded in
TASK-00138's ignored Work evidence; HTTP maps deliberate 403/404/409 intent and keeps unknown/internal faults 500.
MCP/OAuth mapping and real consumer storage/provider/worker qualification remain separate.

## Explicit status and bounded discovery (Unreleased)

`FindInvitationDeliveryStatus` returns `InvitationDeliveryStatusView|CredentialDeliveryAbsent`;
`FindCredentialDeliveryStatus` returns `CredentialDeliveryStatusView|CredentialDeliveryAbsent`. Replace nullable
handling directly: narrow on `CredentialDeliveryAbsent` (serialized `{"state":"absent"}`), otherwise consume the
safe View. There is no nullable compatibility mode. The invitation View additionally requires `ActivationOrigin`
and exposes `getOrigin()` / `origin`; supply the recorded grant origin to `fromWork()` or direct construction.

Invitation reads require the exact requested User; credential reads require the exact purpose/delivery UUID.
Both validate available grant/delivery ownership and expiry correlation. Retained terminal history remains present,
not absent. A contradictory repository result is an internal fault; storage exceptions propagate unchanged.
Absence proves neither rollback nor permission to issue, resend, redeem or disclose another generation. Consumers
still protect every status/worker entry and sanitize errors; Common's opt-in safe HTTP boundary renders internal/
unknown storage faults as generic server errors, not absence or diagnostic-derived not-found outcomes.

Due/expired handlers validate each source's list, element family, admitted time, order, unique User/delivery IDs
and individual maximum before merging. Invitation/reset expired work must retain recoverable delivery status;
email expiry selects issued authority regardless of terminal delivery. Repository adapters still own latest/issued,
material and applicable User-authority eligibility; the safe DTOs cannot prove those facts. No readbacks, locks,
stronger cross-family snapshot or new lifecycle policy are added. Three valid source lists can exceed the final
limit: merge by time instant, delivery ID and purpose, then select global top-N. Due limits remain positive with
no new maximum; expired limits remain 1–100. Empty lists are ordinary success, never a substitute for failure.

All four queries remain read-only and advisory: no claim, expiry, cleanup, admission, transaction, commit, audit
or Events. Commands recheck final authority/state. `CredentialQueryContractTest` and
`CredentialQueryCompositionTest` cover source rejection, terminal history, real Common registration/routing,
serialized Query dispatch, safe-error distinction and unchanged persisted state. Static fixtures prove handler
result unions/list elements and absence narrowing, not generic bus inference (TASK-00128's separate supplier).
The installed tagged Common 1.4.1 supports these runtime contracts; minimum/ordinary lane and exact candidate
verification belong to TASK-00133's local handoff, not consumer database/provider qualification.

## Precise Domain type contracts (Unreleased)

Human credential Commands, Queries and Events decode required fields without scalar or object coercion: identity,
actor, email, purpose and timestamp payload fields must be strings, and discovery limits must be integers.
Timestamp-bearing payloads require an explicit valid RFC3339 instant, never relative dates or calendar rollover.
Canonical field names, nullability and existing timestamp output precision are unchanged. `fromArray()` remains a
dynamic input boundary; exact `toArray()` shapes describe validated output, not unchecked input assertions.

`DueCredentialDelivery` and `ExpiredCredentialDelivery` expose the union of the three purpose-specific delivery ID
types rather than arbitrary `UniqueId`. Due-work construction validates the purpose/ID pair, nonnegative observed
revision and pending/claimed/retry-pending status. Expired work retains its existing required email-grant ID only
for email change; email authority may need cleanup even after delivery is terminal. Safe status projection rejects
a mismatched purpose/delivery family or negative revision. The OpenAPI due-work status and revision bounds agree.
These DTOs are advisory descriptions, not worker admission, current authority or proof of provider delivery.

Grant transitions now declare `static` results, preserving the actual subtype through immutable replacements and
no-ops. Consumer subclasses overriding these methods must preserve that covariant return contract. Update consumers
that relied on coerced payload fields or fabricated discovery/status values directly; no compatibility mode is
provided. Repositories still return aggregate-or-null and retain complete-state, generation and transaction fences.
No Query absence/failure redesign, generic bus adoption, new recovery policy or consumer qualification is included.

`HumanCredentialPayloadTest` covers every owned message decoder's current round trip and missing/wrong-type fields;
`CredentialWorkIdentityTest` covers purpose/revision/status relationships. Existing grant, worker, repository,
expiry/recovery and generated-schema suites remain preservation evidence. Detailed declaration/member dispositions
and standalone positive/negative static probes belong to the canonical TASK-00116 Work evidence, not public logs.

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

For the current password-reset persisted authority, mandatory shared User fence, lifecycle cancellation and worker
constructor wiring, follow [recovery composition](operation-outcomes.md#recovery-composition). Delivery remains
separate from redemption authority; consumers qualify their actual all-writer transaction and restart behavior.

## Activation origin (v0.7.0)

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
latest invitation status exposes `activation_delivery_id` and recorded `origin` for administrator inspection;
both are advisory, not recovery or dispatch authority.
Immediate direct dispatch and event-triggered dispatch use that same worker. Both origins are delivered
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

Released in [v0.7.0](release-0.7.0.md), this is a **breaking pre-v1 minor** current API/persistence change, not a
v0.6.x patch or an old-data migration route.
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
sanitize rethrown faults and secure event/log access. The current [operation-outcome contract](operation-outcomes.md)
also applies to reset/email-change workers, their request handlers and manual invitation retry.

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
formats were unchanged. The v0.7.0 activation-origin contract above separately changes issuance/hydration and
provider composition plus required persisted state.

Inside the shared transaction, the handler reads the latest grant, then takes one fresh clock sample before mutation,
complete-state CAS and audit. Only still-issued authority with `now < expiresAt` and material-bearing `RETRY_PENDING`
delivery may retry. Exact expiry, including fractional/timezone-equivalent equality, rejects. Missing, consumed,
revoked or ineligible delivery also rejects with Common Application `StateConflict` and constant text
`The activation delivery work is no longer retryable.` A lost CAS retains the same class and constant concurrent-change
message. Map the exception class, not parsed text. Current `CommandFailedEvent` evidence retains the safe command
with constant `Invitation delivery retry failed.` text; notification failure cannot mask the original throwable.
This notification policy supersedes the historical v0.6.0 diagnostic policy, not its retry admission behavior.
Sanitize rethrown faults and event/log boundaries separately in the consumer.

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
