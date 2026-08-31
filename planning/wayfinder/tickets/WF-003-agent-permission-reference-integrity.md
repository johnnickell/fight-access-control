# Protect Agent Permission reference integrity

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Map:** [Agent HMAC authentication and direct authority](../agent-hmac-authentication-map.md)
**Depends on:** [Define framework-neutral HMAC Agent authentication](WF-001-hmac-agent-authentication-boundary.md)

## Question

How does an Agent own duplicate-free direct Permission assignments while preserving authoritative resolution and
the existing fail-closed guarantee that a Permission cannot be removed while any live assignment references it?

## Must decide

- Aggregate methods, revision behavior, and explicit assignment commands for grant, revoke, and complete-set
  replacement of direct Permission identifiers.
- The repository contract and consumer-owned persistence fence that include Agent references in Permission removal,
  concurrency, rollback, and managed-policy reconciliation.
- The rejection behavior for missing, stale, duplicate, or removed Permission authority.
- Which immutable agent read model exposes assigned permission identities without exposing secret material or making
  policy decisions.

## Resolution boundary

This ticket may define direct authority and reference integrity. It must not introduce Agent Roles, Permission-name
prefix rules, managed-policy definitions, endpoint authorization, or a generalized policy engine.

## Resolution

An Agent owns a duplicate-free set of direct Permission assignments. It supports explicit grant, revoke, and
complete-set replacement. Assignment revision starts at `1`, advances only when assignments change, and is separate
from credential revision. Replacement requires the expected assignment revision.

A Permission cannot be removed while an Agent or Role assigns it. Agent assignment updates and Permission removal
share the consumer-owned transaction guard, so stale or referenced removal fails with no partial change and Managed
Policy reconciliation rolls back. Unknown Permissions, duplicate grants, revokes of unassigned Permissions,
duplicate replacement input, and stale revisions also fail with no partial change.

Agent read results include its ID, lifecycle state, credential ID and revision, assigned Permission IDs and names,
and assignment revision. They never expose secrets or decide whether an action is allowed. Existing User principal
snapshots likewise carry Role and Permission IDs and names for UI use; consumers still enforce authorization on the
server. [ADR 0005](../../adr/0005-agent-direct-permission-assignment-revision.md) records the decision. WF-004 now
defines Agent-principal resolution and conformance.
