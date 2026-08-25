# Wayfinder Map: Agent HMAC authentication and direct authority

**Label:** `wayfinder:map`
**Status:** Active

> This map is an **index, not a store**. Each material decision lives in exactly one linked ticket under
> `tickets/`; this map only summarizes the linked resolutions and shows the next decision frontier.

## Destination

Produce an implementation-ready handoff for a framework-neutral `Agent` aggregate: direct Permission authority,
HMAC signed-request authentication, immediate revocation, and an authoritative agent-principal result. Consumers
must retain ownership of transport mapping, persistence, key storage, runtime composition, and permission checks.

**Done** = every linked decision ticket is closed, the remaining fog is resolved or excluded, and the map links
to its resulting epic, PRDs, and/or implementation tickets.

## Notes

- Existing User, Role, Permission, and principal behavior remains the local authority. An Agent is a machine
  principal, not a User, refresh session, framework security user, or AI persona.
- Charting established three constraints: Agent authority is direct Permission assignment only; one HMAC credential
  is active at a time and replacement invalidates its predecessor immediately; and consumers adapt requests into a
  framework-neutral signed-request input.
- The package must not add a production Adapter layer, transport schema, key implementation, or consuming-project
  permission convention.
- Permission removal already fails closed for live references. Any Agent relationship must participate in the same
  consumer-owned reference fence.

## Decisions so far

1. **Charting constraints are settled.** Agents use direct permissions only, rotate a single active HMAC credential
   without a grace credential, and authenticate through a framework-neutral signed-request boundary.
2. **Fight Common integration is settled.** AccessControl owns transport-neutral coordination contracts; consumer
   applications may bridge those contracts to Fight Common `HmacAuthenticator` and `HmacRequestService`. The
   deprecated `HmacWebhookDispatcher` is excluded.
3. **Authentication-result boundary is settled.** Authentication must culminate in an authoritative Agent-principal
   snapshot, rather than an `AgentView` or a follow-up permission query. WF-004 defines that snapshot's precise
   fields and conformance after credential lifecycle and permission-reference integrity are settled.
4. **Canonical compatibility is settled.** Agent HMAC v1 preserves Fight Common's canonical bytes: uppercase method,
   authority, path, normalized query, timestamp, nonce, and a body digest only for a non-empty body. Credential,
   authorization algorithm, and signature remain outside the signed canonical request and are validated separately.
5. **Freshness policy is settled.** Agent HMAC v1 accepts a timestamp no more than five minutes in the past and
   rejects every future timestamp, matching Fight Common's past-only tolerance behavior.
6. **Verification ordering is settled.** A consumer bridge must require a nonce repository and preserve Fight
   Common's order: validate request components and freshness, validate the credential and body digest, verify the
   signature, then atomically consume the nonce. A unique nonce value is global for its validity window; an invalid
   signature therefore cannot consume one and a replay fails closed.
7. **Credential lifecycle is settled.** Explicit provision, rotation, and terminal revocation commands govern one
   public credential ID and consumer-encrypted shared secret. Rotation remains `ACTIVE` while replacing authority
   immediately; `REVOKED` is terminal. Lifecycle work writes secret-free durable audit evidence, commits before its
   success event, and authentication confirms the current credential ID and revision while atomically consuming its
   nonce. [WF-002](tickets/WF-002-agent-credential-revocation-lifecycle.md) records the full decision.
8. **Permission reference integrity is settled.** Agents own duplicate-free direct Permission assignments with a
   separate assignment revision. A Permission cannot be removed while an Agent or Role assigns it; stale or invalid
   assignment work fails with no partial change. Agent reads include safe Permission IDs and names for UI use, while
   consumers remain responsible for server-side authorization. [WF-003](tickets/WF-003-agent-permission-reference-integrity.md)
   records the full decision.

## Tickets

| Ticket | Type | Mode | Status | Depends On |
|---|---|---|---|---|
| [Define framework-neutral HMAC Agent authentication](tickets/WF-001-hmac-agent-authentication-boundary.md) | Grilling / Domain Modeling | HITL | **Closed** | — |
| [Establish Agent credential and revocation lifecycle](tickets/WF-002-agent-credential-revocation-lifecycle.md) | Grilling / Domain Modeling | HITL | **Closed** | WF-001 |
| [Protect Agent Permission reference integrity](tickets/WF-003-agent-permission-reference-integrity.md) | Grilling / Domain Modeling | HITL | **Closed** | WF-001 |
| [Specify Agent principal resolution and conformance](tickets/WF-004-agent-principal-resolution-conformance.md) | Grilling / Domain Modeling | HITL | Open | WF-002, WF-003 |

## Blocking relationships

```text
HMAC authentication boundary ──┬──→ Credential and revocation lifecycle ──┐
                               └──→ Permission reference integrity ───────┼──→ Principal resolution and conformance ──→ Implementation handoff
```

## Frontier

[Specify Agent principal resolution and conformance](tickets/WF-004-agent-principal-resolution-conformance.md) is the
next grillable decision. Its credential-lifecycle and Permission-reference prerequisites are closed.

## Not yet specified (fog)

- Whether credential audit requirements, operator recovery, or multiple active credentials emerge from concrete
  consumer use cases.
- Whether future principal types require a shared abstraction after both User and Agent resolutions are proven.
- Any consumer-specific authorization vocabulary, policy engine, endpoint exposure, or framework integration.

## Out of scope

- AI personas, autonomous-agent behavior, task execution, and delegation.
- Role assignment or permission-prefix conventions for Agents.
- HTTP actions, middleware, security providers, database schemas, key vaults, and encryption-key management.
- Bearer API keys, JWTs, refresh sessions, browser sessions, and a credential grace period for Agents.
