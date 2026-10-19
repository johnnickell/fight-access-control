# v0.6.0 release and integration overview

Selected for preparation on 2026-10-19. This document and the dated changelog are not proof of a signed tag,
published release or consumer qualification. Final-main certification, independent release-content acceptance,
human signing and separately authorized publication remain required.

## Scope and compatibility

This breaking pre-v1 minor contains the expiry-aware manual invitation retry repair accepted in
[TASK-00076](../planning/tasks/00076-TASK.md) and merged through
[PR #118](https://github.com/johnnickell/fight-access-control/pull/118). It builds on the
[v0.5.0 contract](release-0.5.0.md); optional User self-registration remains planned v1.0.0 work, not included behavior.

- PHP **8.5+** and Fight Common **^1.3** requirements are unchanged.
- `RetryInvitationDeliveryHandler` now requires `Clock` as constructor argument **four**, before `EventDispatcher`.
  Update consumer dependency-injection registrations and factories explicitly. There is no optional/system-clock
  fallback, historical constructor or compatibility shim.
- Commands/events, exception class, repository signatures, persisted schema and provider idempotency formats are
  unchanged. No migration/backfill or consumer dependency update is performed by this release preparation.

See [manual retry composition](credential-delivery.md#manual-invitation-retry-admission-v060) for exact types and
[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md) for current-contract-only support.

## Observable behavior

| Scenario | Before (v0.5.0) | v0.6.0 candidate |
| --- | --- | --- |
| Retry at/exceeding activation expiry | Could commit pending retry state/audit; an immediate subscriber could then fail after commit | Fresh post-read transactional sample rejects before mutation/CAS/audit and retry-request publication |
| Valid pre-expiry retry | Retains grant and delivery identity through the existing retry transition | Same authority, identity/history, CAS, rollback and post-commit semantics |
| Failure notification itself throws | Could mask the original rejection/failure | Original throwable remains primary |

Exact expiry includes fractional-second/timezone-equivalent equality. Missing, consumed, revoked or otherwise
ineligible delivery remains rejected through `ActivationDeliveryNotRetryableException`. Expected expiry rejection
uses constant text `The activation delivery work is no longer retryable.`; consumers map the class, not parsed text.
Rejected retry neither cleans up material nor issues new authority. Use explicit authorized fresh-grant resend for
expired still-issued invitations; cleanup remains a separate [expiry operation](credential-expiry.md).

The clock sample follows latest-state retrieval inside the transaction. It is **admission-time**, not a commit or
completion deadline: expiry after admission may reject the worker's claim after retry state/audit committed.
Notification failure does not undo that commit. Provider invocation remains outside transactions after a separately
committed claim, with at-least-once semantics and the original delivery ID. No exactly-once guarantee is introduced.

## Verification and adoption boundary

The implementation has independent technical acceptance and behavioral QA at its recorded candidate. Package
regressions cover exact/before/after expiry with subscriber off/on, fractional equality, delayed reads, same identity,
fresh resend, eligibility, modeled competing writers, persisted rollback, notification faults and post-admission expiry.
The canonical TASK owns detailed evidence; release preparation and final-main certification have separate receipts.

Consumers must protect every entry point and sanitize rethrown infrastructure faults and event/log diagnostics.
Once publication is verified, deliberately select the actual tag/resolved source and qualify the installed package
against real shared-connection PostgreSQL transactions, hydration/CAS, competing writers and rollback, physical-clock
behavior, and provider deduplication. Repeat before/exact/after expiry with subscriber off/on, unchanged row/revision/
audit on rejection, fresh-ID resend, same-ID valid retry and post-commit failure scenarios.

Controlled package tests are not consumer qualification. Publication does not authorize a consumer source/lock update,
data operation or deployment, and does not close a downstream integration blocker.
