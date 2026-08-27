---
id: T-00022
prd: PRD-00002
title: Authenticate a signed Agent request
status: done
blocked_by: T-00020
---

# Authenticate a signed Agent request

## Outcome

A consumer can provide a framework-neutral signed request and receive a verified current Agent authority result only
when its canonical request, timestamp, body digest, credential, signature, and one-time nonce are valid.

## Scope

- In scope: portable signed-request and authentication result contracts, Fight Common HMAC bridge, five-minute
  past-only timestamp validation, body-digest behavior, atomic nonce consumption, and deterministic test ports.
- Out of scope: HTTP headers or middleware, nonce-store technology, direct Permission resolution, request-scoped
  Agent-principal caching, authorization policy, production adapters, and denial-response formatting.

## Acceptance Criteria

- [x] The signed-request shape preserves the approved canonical method, authority, path, query, timestamp, nonce,
  and non-empty-body digest behavior.
- [x] Malformed components, future or expired timestamps, credential mismatch, wrong digest, invalid signature, and
  replay fail closed.
- [x] An invalid signature never consumes a nonce; at most one valid request can consume a nonce in its validity window.
- [x] Authentication confirms that the credential ID and revision are still current at nonce consumption.
- [x] Tests prove canonical compatibility, validation order, lifecycle-race outcomes, and exact coverage.

## Verification

- Focused signed-request authentication and nonce-consumption tests
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- `./bin/planning-check` passed.
- `./bin/build` passed: 586 tests, 4310 assertions, and exact 4506/4506 statement coverage.
