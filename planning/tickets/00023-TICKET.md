---
id: T-00023
prd: PRD-00002
title: Resolve the current Agent identity per request
status: ready-for-agent
blocked_by: T-00021,T-00022
---

# Resolve the current Agent identity per request

## Outcome

An application can resolve one immutable Agent identity for a request, including its current credential and direct
Permission snapshots. Every invalid authority condition produces the same generic caller-visible denial while safe
diagnostic classification and correlation information remain available to the consumer’s server-side observability.

## Scope

- In scope: immutable Agent principal, request-scoped provider, authoritative direct-Permission snapshot,
  generic-failure result and safe diagnostics, and portable behavioral conformance tests.
- Out of scope: User-principal changes, refresh sessions, HTTP response mapping, logging or metrics implementation,
  endpoint policy, framework middleware, production adapters, and consumer UI.

## Acceptance Criteria

- [ ] A successful resolution returns Agent ID, current credential ID and revision, assignment revision, and direct
  Permission IDs and canonical names in an immutable snapshot.
- [ ] The same request resolves once and returns the same snapshot for its lifetime.
- [ ] Revoked authority, stale credentials, authentication failure, missing Permissions, and stale assignments each
  result in one generic denial with no partial principal.
- [ ] Diagnostics expose only a safe classification and consumer correlation identifier; they exclude request data,
  signatures, nonces, and shared secrets.
- [ ] Permission checks report only presence in the snapshot; consumer applications retain policy and HTTP mapping.
- [ ] Behavioral tests prove the same outcomes without requiring common framework middleware, tables, or containers.

## Verification

- Focused Agent-principal and request-scoped provider tests plus behavioral conformance tests
- `./bin/planning-check`
- `./bin/build`
