---
id: TICKET-00007
epic: EPIC-00006
title: Deliver Credentials Recoverably Outside Transactions
status: ready-for-agent
---

# Deliver Credentials Recoverably Outside Transactions

## Problem and Outcome

`v0.2.0` commits invitation, reset, and email-change grant delivery material atomically, but only invitation and
email change invoke providers—and do so inside the transaction. Password reset has no package-owned recoverable
provider path. A crash after an originating commit can lose immediate scheduling, and a crash after provider
acceptance but before outcome commit can repeat a delivery without a declared idempotency contract.

Provide one package-owned, provider-neutral lifecycle for all three credential families. Consumer applications supply
one transactional connection/Unit of Work, persistence adapters, encryption keys, provider adapters, and worker
scheduling. AccessControl owns due-work discovery, claims, leases, retry/terminal transitions, stale-claim fencing,
outcome/audit semantics, and direct handler registration.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| An invitation, pending-user restoration/correction, or resend creates a new activation generation | Existing originating commands | Due-work discovery | Existing post-commit invitation events | User, grant, encrypted material, and required audit evidence commit together; the new delivery generation becomes discoverable even if immediate dispatch is lost. |
| An active user requests password reset | `RequestPasswordReset` | Due-work discovery | `PasswordResetRequested` | Eligible identity receives a fresh encrypted delivery generation atomically; unknown/ineligible identity remains generic and creates no work. |
| An active user requests email change | `RequestEmailChange` | Due-work discovery | `EmailChangeRequested` | Reservation, grant, encrypted delivery, and required audit evidence commit together; cancellation, expiry, and replacement fence stale work. |
| A consumer worker sees due work | Exact package delivery command | Secret-free due-work query | Optional delivery outcome events | Handler claims one exact generation in a short transaction. A competing worker loses safely; expired leases become due again. |
| A claimed provider invocation returns | Same package handler | Safe status query | Success/retry/terminal events as accepted | Provider call occurs with no transaction open. Matching expected-state outcome commits separately; delivered and permanent outcomes destroy ciphertext, retryable outcomes retain it until expiry. |

## Contract and Failure Rules

- Discovery returns a deterministic, bounded, secret-free order across invitation, password reset, and email change.
  It includes pending work, retry work whose due time has arrived, and abandoned claims whose lease has expired.
- A persisted claim has a unique opaque token, aggregate revision, claimed-at, lease-until, attempt information, and
  delivery generation. Outcome persistence accepts only that exact unexpired expected state.
- The immutable delivery-generation ID is supplied to the provider as its idempotency identity for every attempt;
  retry retains it and replacement creates another. This provides at-least-once semantics only.
- Consumer adapters translate provider behavior into typed delivered, retryable, or permanent results. Unknown
  throwables are secret-free retryable failures; arbitrary exception messages are neither parsed nor persisted.
- Credential material is encrypted before the originating commit. The package materializes it only after a committed
  claim and only for the provider invocation lifetime. No raw credential, hash, token, ciphertext, credential URL,
  provider secret, or arbitrary provider error appears in serialized Commands, Events, Queries, safe views, audit
  evidence, or ordinary diagnostics.
- Existing post-commit events/subscribers may dispatch direct handlers immediately, but scheduled discovery is the
  recovery authority. Consumers do not implement aliases or copied grant-transition policy.
- The implementation must route every newly issued activation delivery consistently: initial invitation, restored
  pending User, corrected pending invitation, resend, and retry. The corrected-invitation event is not currently
  subscribed and is covered by durable discovery even if no immediate route is added.

## Compatibility and Release

- Replace ciphertext-exposing delivery invoker inputs with provider-neutral sensitive invocation/outcome contracts.
  This requires consumer adapter migration and is a breaking pre-1.0 minor release: `v0.3.0`.
- Consumers migrate repository adapters to the revised delivery persistence/CAS/discovery contract on their shared
  connection, register the revised package handlers and provider capability, schedule discovery, and honor the
  idempotency identity. They remove any vendor patch or copied delivery lifecycle.
- Fight Agent OS may upgrade invitation/reset composition independently and defer only its email-change provider
  wiring. Package release and consumer upgrade remain separately authorized.

## Acceptance Evidence

- [ ] Originating mutations roll back user/grant/delivery/audit atomically and commit discoverable work before any
      provider call.
- [ ] No provider call occurs with an originating, claim, or outcome transaction active.
- [ ] Restart discovery schedules pending, due-retry, and expired-lease work deterministically; competing claims and
      stale outcomes cannot mutate the authoritative generation.
- [ ] A crash after provider acceptance and before outcome commit may retry with the same idempotency identity and is
      documented as at-least-once.
- [ ] Invitation initial/restore/correction/resend/retry, reset request/replacement/confirmation/expiry, and email
      request/delivery/cancellation/expiry all obey one compatible lifecycle.
- [ ] Typed retryable/permanent outcomes, ciphertext destruction, safe audit/status, and secret-free messages are
      proven for every delivery family.
- [ ] Direct package handler registration and consumer-owned shared Unit of Work composition are documented and
      covered by conformance seams.
- [ ] Migration guide, changelog entry, SemVer classification, release qualification, `./bin/planning-check`, and
      `./bin/build` pass before a separately authorized tag/release.

## Exclusions

- Production Adapter code, ORM mappings, schema/migration, queue/job framework, event sourcing, provider-specific
  APIs, mail/HTTP templates, consumer framework composition, Agent OS implementation, and exactly-once guarantees.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 37 | [TASK-00037](../tasks/00037-TASK.md) | Model recoverable credential-delivery state and repository contracts | done |
| 38 | [TASK-00038](../tasks/00038-TASK.md) | Orchestrate provider-neutral credential delivery outside transactions | done |
| 39 | [TASK-00039](../tasks/00039-TASK.md) | Qualify the v0.3.0 credential-delivery contract and migration | ready-for-agent |
