# Set retry and terminal-delivery policy

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John approved the retry-until-expiry policy on 2026-09-24.
**Map:** [Recoverable credential delivery](../recoverable-credential-delivery-map.md)
**Depends on:** [Make package delivery state the authoritative durable queue](WF-009-package-owned-credential-delivery-queue.md)

## Question

When a provider reports a retryable credential-delivery failure, should the package keep retrying until the grant
expires, or stop after a fixed attempt budget; and what happens when a provider reports a permanent failure?

## Must decide

- Whether retry eligibility lasts until the existing grant expiry or has a fixed maximum number of provider attempts.
- Whether the package owns bounded backoff and due time, rather than leaving timing policy to each consumer worker.
- Permanent-failure transition, ciphertext destruction, audit evidence, operator-safe status, and no automatic retry.

## Required evidence

- Invitation and email-change state currently preserve ciphertext after a failed invocation, while password reset has
  no failure state or retry path.
- Each grant already has an authoritative expiry; resend/replacement creates a fresh generation and invalidates or
  terminalizes its predecessor.
- Deterministic discovery and abandoned-claim recovery require one package-owned due-time policy, not consumer-
  duplicated lifecycle decisions.

## Resolution boundary

This ticket chooses operational lifecycle policy only. It does not choose a mail vendor, a queue, a worker cadence,
logging backend, or an exact database representation.

## Resolution

Retryable provider failures remain eligible until the owning grant expires. The package owns a bounded increasing
backoff and due time; consumers schedule discovery but do not select lifecycle timing. A replacement or resend creates
a new delivery generation and therefore starts a fresh eligibility period under its new grant expiry.

A typed permanent provider failure is terminal: it destroys recoverable ciphertext, records only safe outcome/audit
evidence, is visible through a secret-free operational status, and is never automatically retried. Unexpected
provider throwables become a safe retryable classification without persisting or parsing arbitrary error text.

The resulting implementation handoff is [EPIC-00006](../../epics/00006-EPIC.md),
[TICKET-00007](../../tickets/00007-TICKET.md), and [TASK-00037](../../tasks/00037-TASK.md) through
[TASK-00039](../../tasks/00039-TASK.md).
