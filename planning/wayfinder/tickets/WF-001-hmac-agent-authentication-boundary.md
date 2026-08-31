# Define framework-neutral HMAC Agent authentication

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Map:** [Agent HMAC authentication and direct authority](../agent-hmac-authentication-map.md)
**Depends on:** —

## Question

What is the package-owned, framework-neutral input and result boundary for authenticating an Agent with a signed
HMAC request, while consumer applications remain responsible for mapping HTTP or another transport into that input?

## Must decide

- The canonical signed components, including method, authority, path, canonical query, timestamp, nonce, and body
  digest; and which malformed or missing components fail closed.
- The application service and value/result contracts that authenticate an Agent without accepting a framework
  request object or returning one.
- How signature verification, timestamp-window validation, and atomic nonce consumption are ordered so an invalid
  signature cannot consume a nonce and a replay cannot succeed.
- Whether the public Fight Common HMAC behavior can be consumed through a framework-neutral package boundary, or
  whether compatibility work is a prerequisite rather than copied HMAC logic.

## Resolution boundary

This ticket may define supported authentication semantics and portable package contracts. It must not define HTTP
headers, routes, middleware, adapter implementations, key storage, authorization policy, or a consuming-project
permission convention.

## Resolution

Agent HMAC v1 is a framework-neutral coordination boundary. Consumer applications map their transport requests to
the package's signed-request value and may bridge it to Fight Common `HmacAuthenticator` and
`HmacRequestService`; `HmacWebhookDispatcher` is excluded.

The canonical request preserves Fight Common v1 bytes: uppercase method, authority, path, normalized query,
timestamp, nonce, and the body digest only when the body is non-empty. Credential, authorization algorithm, and
signature are validated separately. Timestamps are accepted only when they are no more than five minutes in the
past; future timestamps fail closed. The bridge requires a nonce repository and preserves the sequence: validate
components and freshness, validate credential and body digest, verify signature, then atomically consume the nonce.

Authentication culminates in an authoritative Agent-principal snapshot, never an `AgentView` or a follow-up
permission query. [WF-002](WF-002-agent-credential-revocation-lifecycle.md) owns credential lifecycle and the
rotation race; [WF-003](WF-003-agent-permission-reference-integrity.md) owns direct-Permission reference integrity;
[WF-004](WF-004-agent-principal-resolution-conformance.md) defines the snapshot's precise shape and conformance.
