---
id: TICKET-00036
epic: EPIC-00015
title: Clarify Agent name and credential-operation domain ownership
status: done
---

# Clarify Agent name and credential-operation domain ownership

## Problem and Outcome

Provisioning and rename currently normalize the same Agent name differently. The independently persisted
credential operation also appears beneath Agent as though it were only a nested entity, and its repository
exposes write/material capabilities to secret-free readers. Give each concept a clear owner under
[EPIC-00015](../epics/00015-EPIC.md) without weakening correlation, delivery or restoration safety.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Authorized provision/rename supplies a name | Existing provisioning request / UpdateAgent / AgentNameChanged | One AgentName rule; existing rename/no-op and issuance behavior except explicit normalization change. |
| Caller retries retained provision/rotation key | Existing synchronous operation and status Query | Same canonical request/issuance resolution; no duplicate issuance or reusable retired key. |
| Worker claims/delivers/maintains original operation | Existing worker services and discovery Queries | Same separately committed stages, exact authority/claim fences, receipts and tombstones. |
| Authorized reader inspects status/due/maintenance work | Existing Queries and safe Views | Narrow secret-free repository contract, no material, business mutation or write admission. |

## Requirements and Validation

- TASK-00113 makes AgentName own the existing fixed Unicode-edge provisioning rule: NUL plus the fixed whitespace
  set, nonempty UTF-8, at most 120 code points, case/internal whitespace preserved, no NFC conversion. Rename now
  adopts it as the approved breaking pre-v1 behavior change. Command and failure boundaries retain appropriate types.
- Canonical request marker 2, exact request bytes for existing valid provisioning input, original issuance and
  retry comparison remain unchanged. Do not recanonicalize retained keys, introduce historical readers or change
  identity because names now share an implementation. Remove the duplicate name algorithm, not version safety.
- TASK-00114 promotes AgentCredentialOperation into its own Domain aggregate home inside AccessControl with its
  repository/owned concepts. Align Application/query/test homes and current documentation with that responsibility.
  This is not a new bounded context or permission to replace required cross-aggregate atomicity with eventual consistency.
- Separate cohesive secret-free status/scheduler/maintenance read capabilities from material-bearing write
  persistence. Use Domain repository interfaces under current QueryHandler policy; one persistence adapter may
  implement both, but no forwarding-only hierarchy, Store/UoW combination or per-method interface proliferation.
- Preserve scope/destination/target admission before lookup/disclosure, exact expected state, shared cohort and
  restoration fences, destination ordering, key-reference evidence and atomic Agent retirement/operation cancellation.
  Keep domain generation/authority evidence; operational qualification must not move into business entities as flags.
- Preserve current issuance/delivery/retry/maintenance outcomes and the scoped post-commit warning exception.
  Durable material is not an outbox; issuance is not delivery, activation or launch authority. Retained unknown
  markers fail closed without fallback; tombstones/generations are current safety, not obsolete compatibility.

## Dependencies and Evidence

TASK-00113/00114 have orders 203/204 with no technical edge between them: ownership and normalization are separate
knowledge. Follow current source at invocation and preserve the other's accepted contract. Both follow existing
features and block neither native adapters nor unrelated MCP work. Common future namespace work is not a blanket
prerequisite; adopt only actual supported upstream contracts used by the slice.

## Acceptance Evidence

- [ ] Provisioning and rename have independently justified matching Unicode/name expectations, invalid-encoding/
  length rejection and documented intentional rename differences, with exact existing canonical request fixtures.
- [ ] Retained-key retry/restart, original issuance and unsupported-marker rejection remain stable without migration.
- [ ] Concrete before/after callers and the operation/read interfaces reduce unrelated consumer obligations while
  keeping authorization, failure, atomicity and external-effect boundaries explicit.
- [ ] Real package journeys preserve rotation/revocation cancellation, stale claims/admissions, uncertain commits,
  receipt-first recovery, maintenance/key counts, destination ordering and restoration guards after reorganization.
- [ ] Owned imports, mirrored tests, current schemas/composition docs and breakage notes are updated directly;
  focused checks, canonical build/exact coverage and independent Review/applicable QA qualify the package only.

## Exclusions

Consumer persistence implementation/migration, new operation lifecycle/retention policy, Common changes,
compatibility aliases, split transactions, outbox/exactly-once delivery, physical key/credential cleanup and deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 203 | [TASK-00113](../tasks/00113-TASK.md) | Make AgentName own provisioning and rename semantics | done |
| 204 | [TASK-00114](../tasks/00114-TASK.md) | Establish credential-operation aggregate and narrow read repositories | done |
## Decisions and Progress

The terminal status records the original independently accepted children and their administrative parent rollup.
TASK-00114's integrated candidate after develop advanced through TASK-00112 now has independent Review ACCEPT and
QA PASS. Exact evidence belongs to TASK-00114 and its canonical handoffs; the parent status does not itself supply
an independent verdict or claim publication.

John approved both slices and their lower priority than enabling refactors/current features. Existing specialized
atomic repository methods remain valid where they encapsulate actual concurrency knowledge; do not flatten them
solely to match generic CRUD. Planning only; no changed source, runtime proof or acceptance is claimed.

Next: `/skill:next` for Board-based selection, not automatic implementation.
