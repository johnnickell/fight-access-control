# Wayfinder Map: Recoverable credential delivery

**Label:** `wayfinder:map`
**Status:** Closed

> This map is an **index, not a store**. Each material decision lives in exactly one linked decision ticket under
> `tickets/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Produce a decision-complete, repository-native implementation handoff for a `v0.3.0` Fight AccessControl package
release whose invitation, password-reset, and email-change effects are recoverable, provider-neutral, and honestly
at-least-once. Package-owned credential-delivery state must become durable atomically with its originating grant
and must be sufficient to recover work after a process crash; no consumer outbox may duplicate that state machine.

**Done** = every linked decision ticket is closed, the remaining fog is resolved or excluded, and this map links to
its resulting EPIC, TICKET, and implementation TASKs.

## Notes

- Preserve the `Domain <- Application` package boundary. Consumers own persistence adapters, the shared transactional
  connection, key custody, provider adapters, worker scheduling, and capacity; the package owns credential-delivery
  lifecycle, transition policy, and public handlers.
- Events remain optional post-commit coordination only. Scheduled discovery and the same package handlers provide
  restart recovery.
- A provider call must never occur inside an originating, claim, or outcome transaction. It is at-least-once only;
  a stable delivery-generation ID is the provider idempotency identity.
- Raw credentials, hashes, ciphertext, provider secrets, credential URLs, and arbitrary provider errors remain out
  of serializable commands, events, safe views, ordinary durable diagnostics, and logs.

## Decisions so far

1. **[Make package delivery state the authoritative durable queue](tickets/WF-009-package-owned-credential-delivery-queue.md) is settled.** All three credential-delivery families use a package-owned claim/invoke/outcome lifecycle; a consumer outbox is neither required nor authoritative.
2. **[Set retry and terminal-delivery policy](tickets/WF-010-retry-and-terminal-delivery-policy.md) is settled.** Retryable work remains eligible until its existing grant expiry under package-owned bounded increasing backoff; permanent failure is terminal and ciphertext-free.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-009 | [Make package delivery state the authoritative durable queue](tickets/WF-009-package-owned-credential-delivery-queue.md) | Grilling | HITL | **Closed** | — |
| WF-010 | [Set retry and terminal-delivery policy](tickets/WF-010-retry-and-terminal-delivery-policy.md) | Grilling | HITL | **Closed** | WF-009 |

## Blocking relationships

```text
Package-owned credential-delivery queue ──→ Retry and terminal-delivery policy ──→ Implementation handoff
```

## Frontier

No Wayfinder decision remains. The implementation handoff is [EPIC-00006](../epics/00006-EPIC.md),
[TICKET-00007](../tickets/00007-TICKET.md), and [TASK-00037](../tasks/00037-TASK.md) through
[TASK-00039](../tasks/00039-TASK.md).

## Not yet specified (fog)

None. Exact PHP names and serialized shapes are implementation design constrained by the accepted public behavior and
the repository's existing Command, Query, Event, handler, and repository contracts.

## Out of scope

- A production Adapter layer, ORM mapping, database schema, transaction implementation, queue/job platform, event
  sourcing, provider-specific API, HTTP/mail template, or consumer runtime implementation.
- Fight Agent OS implementation, vendor patching, release tag creation, publication, deployment, and cleanup.

## Resolution

[EPIC-00006](../epics/00006-EPIC.md) owns the `v0.3.0` destination. Its [TICKET-00007](../tickets/00007-TICKET.md)
owns the full credential-delivery contract, decomposed into [TASK-00037](../tasks/00037-TASK.md),
[TASK-00038](../tasks/00038-TASK.md), and [TASK-00039](../tasks/00039-TASK.md). Tagging, publication, and consumer
upgrade execution remain separately authorized.
