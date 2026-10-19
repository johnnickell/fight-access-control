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
Command/event payloads, repository signatures, persisted state and provider idempotency formats are unchanged.

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
