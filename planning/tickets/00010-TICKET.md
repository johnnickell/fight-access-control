---
id: TICKET-00010
epic: EPIC-00008
title: Reconcile Protected Managed Policy Safely
status: ready-for-agent
---

# Reconcile Protected Managed Policy Safely

## Problem and Outcome

Managed policy currently validates identities and references but can attach a `SUPER_ADMIN_ONLY` Permission to an
ordinary managed Role or promote a Permission while a custom Role or Agent still has it. Reconciliation must reject
those policies before changing authority. A concurrent grant and promotion cannot both commit.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A consumer supplies a version-controlled managed policy | `ReconcileManagedPolicy` | N/A | `ManagedPolicyReconciled` only after a successful commit | A protected Permission may be included only in the managed Role named exactly `ROLE_SUPER_ADMIN`. Ordinary managed Roles cannot carry it, regardless of Role ID. Invalid definitions fail before any policy change. |
| A consumer promotes an existing managed Permission to `SUPER_ADMIN_ONLY` | `ReconcileManagedPolicy` | Authoritative Role and Agent membership reads inside reconciliation | Existing failure evidence on rejection; no success Event | If a custom Role, ordinary managed Role, or Agent still holds the Permission, reject the entire reconciliation without stripping membership or partially updating tier or Roles. |
| A consumer reconciles an eligible policy | `ReconcileManagedPolicy` | Existing managed-policy reads | `ManagedPolicyReconciled` after commit | Existing exact managed-policy behavior, stable identities, and desired-state outcomes remain intact. A protected Permission may remain on or be added to the designated managed Role only. |
| Grant and promotion overlap | Existing Role or Agent grant Command and `ReconcileManagedPolicy` | Authoritative Permission and membership reads | Only committed operations emit success Events | If grant commits first, promotion fails while membership remains; if promotion commits first, grant fails. The losing operation leaves no partial change. |

No new Command, Query, or Event is required. The application builder protects the reconciliation entry point and
owns the consumer's Permission-name/tier classification.

## Validation, Transactions, and Compatibility

- `ManagedPolicy` and reconciliation reject malformed references, conflicting managed Role identity, and protected
  Permission membership outside the exact-name, authoritative managed `ROLE_SUPER_ADMIN`. A custom or stale Role
  with that name never qualifies; Role identity handling also belongs to [TICKET-00011](00011-TICKET.md).
- Promotion checks authoritative custom-Role, ordinary managed-Role, and Agent memberships before changing tier.
  Reconciliation is atomic: a rejected definition or promotion writes nothing and emits no success Event. It does
  not silently skip or automatically remove forbidden memberships.
- Tier and membership decisions occur inside the reconciliation Unit of Work. Repository contracts preserve the
  authoritative tier and membership state through grant and policy writes. Adapters choose the concrete locking
  strategy, while the package's in-memory repositories and unit tests prove the required conflict outcome.
- Existing stable IDs, exact managed membership reconciliation, reference integrity, and post-commit event
  ordering remain in force. An idempotent grant must still observe authoritative tier state; that grant behavior
  belongs to [TICKET-00009](00009-TICKET.md).
- This changes the managed-policy acceptance contract for the proposed pre-1.0 `v0.4.0` release. Consumer schema,
  PostgreSQL locking tests, HTTP tests, and lockfile updates are separate adoption work.

## Permissions and Rejection Behavior

The consumer defines which managed Permission names are `SUPER_ADMIN_ONLY`; AccessControl enforces the declared
tier and never infers protected status from a Permission name. The application builder authorizes policy deployment
through each command entry point. Package rejection uses its existing failure-event and exception behavior without
exposing private state to HTTP clients through a package transport contract.

## Acceptance Evidence

- [ ] A policy that assigns a protected Permission to an ordinary managed Role fails before reconciliation changes
      authority. Only managed `ROLE_SUPER_ADMIN` may hold it.
- [ ] Promotion fails with no partial write or success Event while any custom Role, ordinary managed Role, or Agent
      holds the Permission; removal of those memberships permits a valid retry.
- [ ] Focused package unit tests exercise both grant-first and promotion-first interleavings or controlled stale
      state and prove that both operations cannot succeed, including no-op grant handling.
- [ ] Existing safe failure publication, transaction rollback, and post-commit success ordering remain proven; the
      canonical `./bin/build` passes during implementation.

## Decision Links and Boundaries

Implements [WF-011](../wayfinder/tickets/WF-011-protected-permission-membership.md),
[WF-015](../wayfinder/tickets/WF-015-forbidden-authority-remediation.md), and
[WF-016](../wayfinder/tickets/WF-016-atomic-enforcement-and-proof.md). Eligible runtime delegation belongs to
[TICKET-00009](00009-TICKET.md); reserved Role and User-role behavior belongs to
[TICKET-00011](00011-TICKET.md).

No historical Agent OS data cleanup, automatic remediation command, specific database lock design, Agent OS
implementation, package tag, or release is part of this Ticket.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 45 | [TASK-00045](../tasks/00045-TASK.md) | Reconcile protected managed policy without forbidden membership | in-progress |
