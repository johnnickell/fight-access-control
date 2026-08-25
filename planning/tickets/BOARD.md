# Ticket Board

Operational execution view for Fight AccessControl. Ticket files are canonical for status and blocking edges;
this board is canonical for recommended order. IDs identify artifacts only. Update this file whenever ticket
status, dependencies, or roadmap priority changes.

Last updated: 2026-08-24

## “What’s Next?” Contract

When `/ask-matt` or a plain “What’s next?” is invoked:

1. **Human decision:** return the item under **Now** when it still requires judgment.
2. **Implementation:** return the first ticket under **Ready Frontier**.
3. If the question is unqualified, return both targets. Never choose by ticket number alone.

## Now

**Human decision:** no new design decision is pending. [WF-004](../wayfinder/tickets/WF-004-agent-principal-resolution-conformance.md)
has settled its decision frontier but awaits a passing canonical build before it can close; no implementation ticket
exists until a later, separately approved `/to-spec` handoff.

## Wayfinder Review

[Agent HMAC authentication and direct authority](../wayfinder/agent-hmac-authentication-map.md) has no remaining
decision question. It stays active while WF-004 awaits its canonical build verification, then pending the separately
approved `/to-spec` implementation handoff; it does not displace the implementation frontier.

## Ready Frontier

These tickets have no unfinished blockers. Work top to bottom unless current context makes another ready ticket
materially cheaper.

No tickets are currently ready.

## Waiting

Waiting tickets retain `ready-for-agent`; their position here is derived from unfinished `blocked_by` edges.

No tickets are currently waiting.

## Needs Info

No tickets currently require a decision authority.

## Recently Closed

| Ticket | Outcome |
|---|---|
| [WF-001](../wayfinder/tickets/WF-001-hmac-agent-authentication-boundary.md) | Closed the portable Agent HMAC signed-request, Common integration, freshness, replay, and result-boundary decisions. |
| [WF-002](../wayfinder/tickets/WF-002-agent-credential-revocation-lifecycle.md) | Closed the single-credential lifecycle, secret custody, durable audit ordering, and authentication-race decisions. |
| [WF-003](../wayfinder/tickets/WF-003-agent-permission-reference-integrity.md) | Closed direct Agent Permission assignment, safe named read results, and fail-closed Permission-reference integrity. |

## Recently Done

| Ticket | Parent PRD | Outcome |
|--------|------------|---------|
| [T-00001](00001-TICKET.md) through [T-00012](00012-TICKET.md), [T-00014](00014-TICKET.md) through [T-00018](00018-TICKET.md) | [PRD-00001](../specs/00001-PRD.md) | Identity, credential, session, authorization, administrative-read, grant-persistence, managed-policy, and post-commit security-email delivery-event capabilities are complete with their recorded acceptance and delivery evidence. |
