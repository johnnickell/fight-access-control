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

**Human decision:** [Define framework-neutral HMAC Agent authentication](../wayfinder/tickets/WF-001-hmac-agent-authentication-boundary.md)
is the active unblocked Wayfinder decision. It must settle the portable signed-request and authentication-result
boundary before an Agent aggregate becomes implementation planning.

## Wayfinder Review

[Agent HMAC authentication and direct authority](../wayfinder/agent-hmac-authentication-map.md) is active. Its
[Define framework-neutral HMAC Agent authentication](../wayfinder/tickets/WF-001-hmac-agent-authentication-boundary.md)
decision is the next `/grill-with-docs` candidate; it does not displace the implementation frontier.

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

No tickets are currently closed without implementation.

## Recently Done

| Ticket | Parent PRD | Outcome |
|--------|------------|---------|
| [T-00001](00001-TICKET.md) through [T-00012](00012-TICKET.md), [T-00014](00014-TICKET.md) through [T-00018](00018-TICKET.md) | [PRD-00001](../specs/00001-PRD.md) | Identity, credential, session, authorization, administrative-read, grant-persistence, managed-policy, and post-commit security-email delivery-event capabilities are complete with their recorded acceptance and delivery evidence. |
