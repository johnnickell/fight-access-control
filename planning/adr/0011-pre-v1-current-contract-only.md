# ADR 0011: Pre-v1 current contract only

- Status: accepted by John
- Date: 2026-09-30

## Decision

While AccessControl is pre-`1.0.0`, implement only the current chosen API and persisted contract. John explicitly
rejected any extra code to support previous iterations. This package-wide decision supersedes the narrower
TASK-00057 canonicalization amendment and any conflicting compatibility, upgrade or migration obligation in existing
planning, project guidance or ADRs.

- Remove obsolete APIs, aliases, rejection-only stubs, historical readers, version dispatch, legacy-state branches,
  compatibility defaults and upgrade/backfill machinery whose only purpose is supporting a previous iteration.
  Do not replace them with wrappers, deprecated paths or speculative extension points.
- Do not require old data, payloads, signatures or binaries to continue working. There is no package-owned migration,
  backward-compatibility or cross-version rollback obligation. Unsupported input/state rejects under ordinary current
  validation; rejection does not require keeping the retired API callable.
- Tests, fixtures and public schemas describe the current contract, not obsolete alternatives. Preserve historical
  decisions, released changelog entries and review receipts as history, not requirements to retain code.
- Keep current-contract correctness: authoritative hydration, authentication/authorization, atomicity, idempotency,
  restart recovery, retained operation keys, receipts/tombstones, monotonic ordering and stale-writer rejection.
  Restoring stale data behind external effects is a current-contract safety problem, not version compatibility.
  A version marker or generation may remain only when it serves current validation/fencing, not historical dispatch.
- Consumer integration guidance documents the current contract and its safety prerequisites, not a supported route
  from older package iterations. This decision authorizes neither destructive data resets nor consumer deployment.

The existing pre-v1 release classification and changelog rules (including ADR 0007's breaking minor releases) remain
in force. Reporting a breaking change does not require a compatibility implementation. Revisit stability guarantees
explicitly before `1.0.0`; do not build them speculatively now.

## Consequences and implementation ownership

[TASK-00068](../tasks/00068-TASK.md) removes remaining package compatibility code and its tests/schema/guidance.
TASK-00055's legacy-Agent acceptance remains historical, not a continuing requirement. TICKET-00014 M1 is superseded
by removal, M2/M3 retain only current-contract safety, TASK-00058 covers same-contract restoration only, and
TASK-00059 supplies current integration guidance and evidence rather than a migration guide.

This records policy and pending cleanup, not completed code removal or a fresh implementation acceptance.
