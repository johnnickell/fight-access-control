# Credential delivery migration for v0.3.0

Fight AccessControl `v0.3.0` replaces the `v0.2.x` invitation and email-change invoker model with one recoverable,
provider-neutral delivery lifecycle shared by invitation, password reset, and email change. This is an intentional
breaking change while the package is pre-`1.0.0`.

This guide describes the consumer migration contract. It does not authorize or claim the `v0.3.0` tag, signing,
publication, a consumer upgrade, or deployment.

## Replace the v0.2.x contracts

There is no compatibility bridge. Remove old bindings and update persistence before enabling the new workers.

| v0.2.x contract | v0.3.0 replacement |
| --- | --- |
| `InvitationDeliveryInvoker::invoke(ActivationDelivery): void` | One `CredentialDeliveryProvider::deliver(CredentialDeliveryInvocation): CredentialDeliveryOutcome` binding |
| `EmailChangeDeliveryInvoker::invoke(EmailChangeDelivery): void` | The same provider-neutral `CredentialDeliveryProvider` binding |
| No package-owned password-reset provider path | `DeliverPasswordReset`, `DeliverPasswordResetHandler`, and `PasswordResetDeliverySubscriber` |
| Purpose-specific cipher interfaces with only `encrypt(string): string` | The three purpose-specific cipher interfaces now extend `CredentialDeliveryCipher` and must also implement `decrypt(EncryptedCredentialMaterial): string` |
| `ActivationDelivery::getCiphertext()`, `PasswordResetDelivery::getCiphertext()`, and `EmailChangeDelivery::getCiphertext()` raw-string access | `CredentialDelivery::getEncryptedMaterial()` returns an `EncryptedCredentialMaterial` value for persistence; call `reveal()` only at that boundary |
| `ActivationDeliveryStatus` and `EmailChangeDeliveryStatus` | Shared `CredentialDeliveryStatus` values: `pending`, `claimed`, `retry_pending`, `delivered`, `permanent_failure`, `expired`, and `invalidated` |
| Consumer/invoker interpretation of success and failure | Provider returns `DELIVERED`, `RETRYABLE_FAILURE`, or `PERMANENT_FAILURE`; package handlers own state transitions and safe failure classification |

The provider invocation is deliberately synchronous and non-serializable. It contains the purpose, stable delivery
ID, destination email, and short-lived raw credential. Do not put `CredentialDeliveryInvocation` on a queue, retain
it, export it to diagnostics, or turn its credential into a command or event. Queue only the package's secret-free
direct delivery commands.

## Migrate persistence on one shared transaction connection

`ActivationGrantRepository`, `PasswordResetGrantRepository`, and `EmailChangeGrantRepository` now each implement
`findDue(DateTimeImmutable $at, int $limit): array`. Their existing writes must also compare and persist the complete
credential-delivery state owned by the aggregate:

- delivery ID, owning User ID, canonical destination, encrypted material, expiry, due time, and status;
- optional opaque claim token, claimed time, and lease deadline;
- attempt count, latest attempt time, latest outcome time, and safe failure classification; and
- the owning aggregate revision used by complete-state compare-and-set replacement.

`EncryptedCredentialMaterial` is a boundary value, not a new encryption format. Persist `reveal()` using the existing
ciphertext storage and reconstitute it with `EncryptedCredentialMaterial::fromString()`. Never project that value into
due work, status views, messages, audit evidence, or logs.

All repositories injected into one package handler must participate in the same physical connection and the same
`TransactionalUnitOfWork` callback. Depending on the originating use case, that includes the User, activation grant,
password-reset grant, email-change grant, email reservation, and audit-evidence adapters. A callback failure must roll
back every staged write. Do not commit one repository independently or bind package repositories to connections that
cannot join that transaction.

Repository implementations must preserve these rules:

1. `add`, successor operations, and `replace` stage writes until the surrounding transaction commits.
2. `replace($predecessor, $replacement)` compares the predecessor's complete persisted security state, including the
   aggregate revision and every delivery field. A stale or fabricated predecessor returns `false` without mutation.
3. `findDue()` returns only each User's authoritative latest generation. Pending and due-retry work is eligible at its
   due time; claimed work becomes eligible at lease expiry. Results are secret-free, ordered by eligibility time then
   delivery ID, and bounded by the positive limit.
4. Replacement or resend creates a fresh delivery ID. Retry and abandoned-claim recovery retain the existing delivery
   ID so every attempt has the same provider idempotency identity.
5. Delivered, permanent-failure, expired, invalidated, revoked, and consumed transitions destroy recoverable
   ciphertext as required by the owning aggregate.

### Existing rows

Quiesce old delivery workers before migrating rows. Preserve every delivery ID: changing it defeats stale-generation
fencing and the identity used for new v0.3 provider attempts. Preservation alone does not deduplicate a historical
v0.2 invocation, because the old invokers were not required to send that ID to their provider.

Quarantine every recoverable v0.2 delivery that could already have produced an external effect. For activation and
email change, this includes every old `pending`, `claimed`, or `failed` row: the old handler called the provider inside
the transaction, so acceptance followed by rollback can leave `pending`, acceptance followed by a timeout can commit
`failed`, and `claimed` is also uncertain. Password reset had no package-owned provider call, but its raw ciphertext
was available to a consumer-owned transport and a separate `ConfirmPasswordResetDelivery` command destroyed material
only afterward. A transport acceptance followed by a crash or failed confirmation can therefore leave a recoverable
password-reset row too. Do not make any such row due until its historical invocation has a recorded disposition.

For each quarantined row, use one of these consumer-owned migration paths:

1. Reconcile provider or transport history and migrate an accepted effect to `delivered` with no encrypted material
   and the terminal `due_at` defined below.
2. Seed the provider's deduplication store so the preserved delivery ID resolves to the historical effect, then
   migrate the unresolved row to the retryable target in the table below and let replay converge on that effect.
3. Destroy the material and migrate to `invalidated` with the terminal `due_at` when an operator decides that
   suppressing a possible effect is safer than replay.
4. If neither history nor deduplication is available and replay is required, record explicit owner acceptance of a
   possible duplicate before choosing the retryable target. Do not describe that choice as exactly-once.

A recoverable password-reset row may become immediately eligible only when trustworthy consumer history proves that
its credential was never submitted. Otherwise it requires one of the same accepted-effect, deduplication,
invalidation, or controlled-duplicate dispositions above. The absence of a package-owned v0.2 provider path is not
evidence that no consumer-owned transport effect occurred.

Set every required `due_at` deterministically before reconstitution. An active `pending` or `retry_pending` target
uses the migration cutoff so it is immediately eligible. An `expired` target uses `expires_at`. A `delivered` or
`invalidated` target uses the generation's trustworthy original issuance or first-eligibility timestamp when that
history exists; otherwise it uses `expires_at` as the deterministic terminal fallback. Call this value the terminal
`due_at`. The fallback does not assert when delivery or invalidation happened: terminal rows are never discoverable,
but `due_at` remains required complete state, participates in compare-and-set equality, and is exposed by the safe
status query.

Apply this complete family-specific mapping at the migration cutoff:

| v0.2 family and persisted shape | v0.3 delivery mapping |
| --- | --- |
| Activation with material, an issued grant before expiry, and old `pending` after reconciliation | `pending`, `due_at` equal to the migration cutoff, with no claim metadata |
| Activation with material, an issued grant before expiry, and old `claimed` after reconciliation | `pending`, `due_at` equal to the migration cutoff, with no claim metadata |
| Activation with material, an issued grant before expiry, and old `failed` after reconciliation | `retry_pending`, `due_at` equal to the migration cutoff, with only safe failure metadata |
| Activation with no material and old `confirmed` | `delivered`, with terminal `due_at` and no claim metadata |
| Activation with no material and old `expired`, or recoverable activation work at or after delivery expiry | `expired`, `due_at` equal to `expires_at`, with no material or claim metadata |
| Activation with no material and old `pending`, `claimed`, or `failed` after grant consumption, revocation, or another material-destroying invalidation | `invalidated`, with terminal `due_at` and no claim metadata; never retain an active status without material |
| Email change with material, issued authority before expiry, and old `pending`, `claimed`, or `failed` after reconciliation | Map respectively to `pending`, `pending`, or `retry_pending`, with `due_at` equal to the migration cutoff and no claim metadata |
| Email change with no material and old `confirmed` | `delivered`, with terminal `due_at` and no claim metadata |
| Email change with material after consumption, revocation, or the authority expiry boundary | Destroy the material and map to `invalidated` with terminal `due_at`; email-change aggregate expiry invalidates delivery rather than creating `expired` delivery state |
| Email change with no material and old `pending`, `claimed`, or `failed` after consumption, revocation, aggregate expiry, or another material-destroying invalidation | `invalidated`, with terminal `due_at` and no claim metadata; never retain an active status without material |
| Password reset with material and issued, unexpired authority after reconciliation | `pending`, `due_at` equal to the migration cutoff, with no claim or outcome metadata |
| Password reset with material at or after delivery expiry | `expired`, `due_at` equal to `expires_at`, with no material or claim metadata |
| Password reset with material after grant consumption or revocation | `invalidated`, with terminal `due_at` and no material or claim metadata |
| Password reset with no material | Use trustworthy event, audit, or adapter history: a confirmed delivery maps to `delivered` with terminal `due_at`; delivery expiry maps to `expired` with `due_at` equal to `expires_at`; and consumption, revocation, or explicit invalidation without prior confirmation maps to `invalidated` with terminal `due_at`. The v0.2 row alone cannot distinguish these outcomes. |

For a ciphertext-free password-reset row without trustworthy history, require an explicit migration-owner decision and
conservatively classify it as `invalidated` with the deterministic terminal `due_at`; do not guess `delivered` or
`expired`, recreate material, or make it due. Reject any remaining source combination that contradicts its aggregate
terminal fields instead of coercing it into an active state.

Initialize absent claim/outcome timestamps and failure fields to `null`. Use an attempt count of zero only for work
that trustworthy history shows was never claimed or submitted to a consumer transport. An old `claimed`, `failed`, or
`confirmed` state or a recorded password-reset transport submission proves at least one attempt, and a retry-restored
old `pending` row may also have been attempted. Do not invent an exact count beyond known history. Validate
representative rows from every mapping through aggregate reconstitution and repository invariants before enabling
discovery.

## Register direct package capabilities

Bind the three purpose-specific ciphers and one `CredentialDeliveryProvider`, then register these package handlers by
their published static registration methods:

| Registration | Package implementation |
| --- | --- |
| `DeliverUserInvitation` | `DeliverUserInvitationHandler` |
| `DeliverPasswordReset` | `DeliverPasswordResetHandler` |
| `DeliverEmailChange` | `DeliverEmailChangeHandler` |
| `FindDueCredentialDeliveries` | `FindDueCredentialDeliveriesHandler` |
| `FindCredentialDeliveryStatus` | `FindCredentialDeliveryStatusHandler` |

The existing `InvitationDeliverySubscriber` and `EmailChangeDeliverySubscriber` remain optional immediate routes.
Register the new `PasswordResetDeliverySubscriber` if password-reset events should trigger an immediate attempt.
These subscribers run only after the originating commit and are not recovery authority.

## Schedule recovery work

Run `FindDueCredentialDeliveries` on a recurring consumer-owned schedule with the current time and a positive page
limit. For each returned `DueCredentialDelivery`, dispatch the exact direct command selected by `purpose`:

| Purpose | Command and identifiers from due work |
| --- | --- |
| `activation` | `DeliverUserInvitation` with User ID and `ActivationDeliveryId` |
| `password_reset` | `DeliverPasswordReset` with User ID and `PasswordResetDeliveryId` |
| `email_change` | `DeliverEmailChange` with User ID and `EmailChangeDeliveryId` |

Supply the command's actor using the consumer's established audit identity policy; never derive credentials or a
destination from scheduler data. Paging depends on the dispatch mode:

- With synchronous dispatch, wait for every direct handler in the page to complete its claim and delivery attempt
  before re-running discovery. Continue until discovery returns fewer than the requested limit, then wait for the next
  scheduled interval.
- With asynchronous queue dispatch, enqueue at most one bounded page per scheduling cycle, then stop. Rediscover only
  on a later cycle after workers have had an opportunity to commit their claims; do not loop merely because the page
  was full, because the same rows remain due until those claims commit.

Concurrent workers may read the same item: the first committed complete-state claim wins, and a loser must not invoke
the provider.

Discovery recovers pending work after a lost post-commit dispatch, retries at package-owned due times, and reclaims an
abandoned claim after its lease expires. The delivery handler performs three ordered phases:

1. claim one exact generation in a short transaction and commit;
2. decrypt and call the provider with no transaction open; and
3. commit one typed outcome only if the claim token, lease, delivery ID, and aggregate revision still match.

Consumers must not add a second outbox or copy claim, lease, backoff, retry, stale-outcome, terminalization, or
ciphertext-destruction policy. Infrastructure may schedule and dispatch package messages, but AccessControl remains the
authoritative durable queue.

## Honor the at-least-once provider boundary

Use `CredentialDeliveryInvocation::getIdempotencyId()` as the provider's idempotency key. It is the immutable delivery
generation ID and is reused for every retry, including recovery after provider acceptance followed by an outcome
commit failure. A replacement delivery has a different ID.

The package guarantees at-least-once invocation, not exactly-once effect. The provider adapter must make repeated
calls with the same identity converge on one external effect and return a typed outcome. It must not parse or persist
vendor exception messages as lifecycle state. Prefer translating known provider responses to the three typed outcomes;
an unexpected throwable is converted by the package to `unexpected_provider`, retained only as safe retryable state.

Use `FindCredentialDeliveryStatus` for secret-free operational inspection. Do not log raw credentials, hashes,
ciphertext, credential-bearing URLs, provider secrets, claim tokens, or arbitrary provider errors. The invocation
fails closed on serialization and redacts ordinary object diagnostics, but adapters must still avoid custom secret
capture.

## Fight Agent OS sequencing

Fight Agent OS may upgrade and compose invitation and password-reset delivery independently while its email-change
feature remains unavailable. It may defer only its consumer-owned email-change provider wiring. It must still consume
the package's `v0.3.0` lifecycle and repository contract and must not retain the removed invoker, invent an alternate
email-change state machine, or claim that package email-change behavior was removed. Before enabling email change, it
must register the same direct handler/provider path and scheduled recovery behavior.

## Qualification evidence

The package test suite provides executable contract evidence at the Domain/Application boundary; consumer persistence
adapters should run equivalent cases against their real shared connection.

| Required behavior | Package evidence |
| --- | --- |
| Originating aggregate, delivery, and audit rollback together | [`ResendInvitationDeliveryHandlerTest`](../tests/Application/AccessControl/ActivationGrant/CommandHandler/ResendInvitationDeliveryHandlerTest.php), [`RequestPasswordResetHandlerTest`](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/RequestPasswordResetHandlerTest.php), and [`RequestEmailChangeHandlerTest`](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/RequestEmailChangeHandlerTest.php) |
| Restart discovery returns pending, due-retry, and expired-lease work in deterministic bounded order | [`ActivationDeliveryLifecycleTest`](../tests/Domain/AccessControl/ActivationGrant/ActivationDeliveryLifecycleTest.php) proves due-retry and expired-lease eligibility; the three in-memory repository tests and [`CredentialDeliveryQueryHandlerTest`](../tests/Application/AccessControl/CredentialDelivery/QueryHandler/CredentialDeliveryQueryHandlerTest.php) prove bounded cross-purpose discovery and ordering |
| Provider invocation occurs after claim commit and outside claim/outcome transactions | [`DeliverUserInvitationHandlerTest`](../tests/Application/AccessControl/ActivationGrant/CommandHandler/DeliverUserInvitationHandlerTest.php), [`DeliverPasswordResetHandlerTest`](../tests/Application/AccessControl/PasswordResetGrant/CommandHandler/DeliverPasswordResetHandlerTest.php), and [`DeliverEmailChangeHandlerTest`](../tests/Application/AccessControl/EmailChangeGrant/CommandHandler/DeliverEmailChangeHandlerTest.php) |
| Provider acceptance followed by outcome failure reuses one effect identity | `DeliverUserInvitationHandlerTest::test_provider_acceptance_followed_by_outcome_commit_failure_reuses_idempotency_identity` |
| Competing claims lose before provider invocation and stale outcomes cannot overwrite current state | The three delivery-handler tests plus [`InMemoryActivationGrantRepositoryTest`](../tests/Application/AccessControl/ActivationGrant/Repository/InMemoryActivationGrantRepositoryTest.php) |
| Expired leases become due and reject the abandoned claimant's later outcome | [`ActivationDeliveryLifecycleTest`](../tests/Domain/AccessControl/ActivationGrant/ActivationDeliveryLifecycleTest.php) and the repository discovery tests |
| Due/status results are secret-free and provider material cannot be serialized or exported through ordinary diagnostics | [`DueCredentialDeliveryTest`](../tests/Domain/AccessControl/CredentialDelivery/DueCredentialDeliveryTest.php), `CredentialDeliveryQueryHandlerTest`, and [`CredentialDeliveryInvocationTest`](../tests/Application/AccessControl/CredentialDelivery/Service/CredentialDeliveryInvocationTest.php) |

Run these focused tests during adapter migration, then run this package's complete `./bin/build`. A package build and
local qualification receipt do not certify a consumer adapter, hosted CI, a tag, signing, publication, upgrade, or
deployment.
