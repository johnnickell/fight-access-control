---
id: T-00027
prd: PRD-00003
title: Resolve complete Agent authority from a signed request
status: done
blocked_by:
---

# Resolve complete Agent authority from a signed request

## Outcome

A consumer can pass one signed Agent request to `CurrentAgentPrincipalProvider` and receive one complete, immutable,
request-cached Agent authority without handling an intermediate authentication result or performing a follow-up
Permission query.

## Scope

- In scope: the complete signed-request-to-principal flow, authentication and authority ordering, nonce outcome,
  credential and Permission-assignment revalidation, exact Permission snapshots, request caching, generic denial,
  safe diagnostics, and removal of the intermediate Agent authentication result.
- Out of scope: the final `SecurityContext` rename and constructor, framework authentication classes, HTTP mapping,
  endpoint authorization policy, persistence or cryptographic adapters, and JWT migration.

## Acceptance Criteria

- [x] `CurrentAgentPrincipalProvider` is the one public consumer-composed Agent flow and returns the complete
  `AuthenticatedAgentPrincipal` directly.
- [x] Agent authentication details remain inside that flow, and the obsolete intermediate authentication result is
  removed without a compatibility alias.
- [x] Malformed requests, invalid freshness or body data, credential rejection, and invalid signatures fail before
  nonce consumption and return only the generic caller-facing denial.
- [x] A valid signature crosses the replay boundary exactly once; later credential, assignment-revision, or
  Permission-resolution failure leaves the nonce consumed and cannot return a partial principal.
- [x] Credential identity and revision, active Agent state, Permission-assignment revision, and exact Permission
  definitions are revalidated before the principal is returned.
- [x] Repeated resolution during one request performs authentication and authority resolution once and returns the
  identical completed principal object thereafter.
- [x] Every denial retains only a safe diagnostic classification and consumer correlation identity, excluding raw
  requests, signatures, nonces, shared secrets, and other credential material.
- [x] Behavioral tests prove the complete success, ordering, replay, concurrency, caching, and diagnostic outcomes
  using deterministic consumer-owned ports with exact executable coverage.

## Verification

- Focused signed-request, Agent-principal, nonce-ordering, authority-fence, caching, and diagnostic tests
- Framework-neutral signed-request-to-principal behavioral conformance tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Verified 2026-08-30: `./bin/planning-check` and `./bin/build` passed. The provider now owns complete signed-request
validation, HMAC verification, nonce consumption, current-authority revalidation, exact Permission resolution, and
identical-object request caching; the obsolete service and result boundary are absent.
