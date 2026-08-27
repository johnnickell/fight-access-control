# Ticket Board

Operational execution view for Fight AccessControl. Ticket files are canonical for status and blocking edges;
this board is canonical for recommended order. IDs identify artifacts only. Update this file whenever ticket
status, dependencies, or roadmap priority changes.

Last updated: 2026-08-27

## “What’s Next?” Contract

When `/ask-matt` or a plain “What’s next?” is invoked:

1. **Human decision:** return the item under **Now** when it still requires judgment.
2. **Implementation:** return the first ticket under **Ready Frontier**.
3. If the question is unqualified, return both targets. Never choose by ticket number alone.

## Now

**Human decision:** no new design decision is pending.

## Wayfinder Review

[Agent HMAC authentication and direct authority](../wayfinder/agent-hmac-authentication-map.md) is closed. Its
decisions are synthesized in [EPIC-00002](../epics/00002-EPIC.md) and [PRD-00002](../specs/00002-PRD.md); it does
not displace the implementation frontier.

## Ready Frontier

These tickets have no unfinished blockers. Work top to bottom unless current context makes another ready ticket
materially cheaper.

No ready-for-agent tickets remain.

## Waiting

No ready-for-agent tickets have unfinished blockers.

## Needs Info

No tickets currently require a decision authority.

## Recently Closed

| Ticket | Outcome |
|---|---|
| [WF-001](../wayfinder/tickets/WF-001-hmac-agent-authentication-boundary.md) | Closed the portable Agent HMAC signed-request, Common integration, freshness, replay, and result-boundary decisions. |
| [WF-002](../wayfinder/tickets/WF-002-agent-credential-revocation-lifecycle.md) | Closed the single-credential lifecycle, secret custody, durable audit ordering, and authentication-race decisions. |
| [WF-003](../wayfinder/tickets/WF-003-agent-permission-reference-integrity.md) | Closed direct Agent Permission assignment, safe named read results, and fail-closed Permission-reference integrity. |
| [WF-004](../wayfinder/tickets/WF-004-agent-principal-resolution-conformance.md) | Closed the distinct Agent principal, request-scoped resolution, safe diagnostics, and consumer-owned authorization boundary after the canonical build passed. |

## Recently Done

| Ticket | Parent PRD | Outcome |
|--------|------------|---------|
| [T-00024](00024-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Unified distinct immutable User and Agent authority snapshots through a narrow shared contract and consumer-composed current-security-context facade, with exact coverage. |
| [T-00023](00023-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Delivered one immutable request-scoped Agent principal with current credential and Permission-assignment fencing, generic secret-free diagnostics, portable behavioral conformance, and exact coverage. |
| [T-00022](00022-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Delivered portable signed-request authentication, canonical HMAC conformance proof, digest validation, and atomic replay/current-credential fencing with exact coverage. |
| [T-00021](00021-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Delivered revision-fenced direct Agent Permission management, shared removal reference integrity, and exact secret-free administrative reads. |
| [T-00020](00020-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Replaced an Agent's sole credential atomically with stale-ID fencing and terminal revocation, preserving secret-free audit/event ordering and exact coverage. |
| [T-00019](00019-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Provisioned one active Agent credential with encrypted durable authority, a post-commit raw-secret result, and secret-free audit/event evidence. |
| [T-00025](00025-TICKET.md) | [PRD-00002](../specs/00002-PRD.md) | Required a normalized, bounded operator-facing Agent name before any Agent credential work, with secret-free failure handling and exact coverage. |
| [T-00001](00001-TICKET.md) through [T-00012](00012-TICKET.md), [T-00014](00014-TICKET.md) through [T-00018](00018-TICKET.md) | [PRD-00001](../specs/00001-PRD.md) | Identity, credential, session, authorization, administrative-read, grant-persistence, managed-policy, and post-commit security-email delivery-event capabilities are complete with their recorded acceptance and delivery evidence. |
