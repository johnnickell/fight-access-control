# Define framework-neutral HMAC Agent authentication

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
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

Write this only when the decision is closed. Link the epic, PRD, or implementation-ticket handoff created by the
resolved map where relevant.
