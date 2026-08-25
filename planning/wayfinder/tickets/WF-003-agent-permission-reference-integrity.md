# Protect Agent Permission reference integrity

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
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

Write this only when the decision is closed. Link the epic, PRD, or implementation-ticket handoff created by the
resolved map where relevant.
