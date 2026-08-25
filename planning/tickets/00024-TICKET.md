---
id: T-00024
prd: PRD-00002
title: Unify current User and Agent authority access
status: ready-for-agent
blocked_by: T-00023
---

# Unify current User and Agent authority access

## Outcome

A consumer can inject one request-scoped security context and ask whether the authenticated User or Agent snapshot
has a named Permission or Role, without treating an administrative view as authentication authority or merging the
two principal identities.

## Scope

- In scope: rename `AuthenticatedPrincipal` to `AuthenticatedUserPrincipal`; define a narrow shared authenticated-
  authority contract; make the User and Agent principal snapshots implement it; and provide a consumer-composed
  request-scoped `CurrentSecurityContext` facade.
- The facade exposes the current immutable authority snapshot and delegates `hasPermission()` and `hasRole()` to it.
  Agent authority has direct Permissions only, so its role check returns `false`.
- Consumers retain selection of the request's User or Agent authentication path and must fail closed when it is
  absent or ambiguous. The package does not inspect HTTP, headers, cookies, middleware, or framework tokens.
- Out of scope: a common aggregate, a User-or-Agent administrative `View`, Agent Roles, authorization-policy
  decisions, transport authentication selection, production adapters, or consumer runtime composition.

## Acceptance Criteria

- [ ] The existing immutable User snapshot is consistently named `AuthenticatedUserPrincipal`; its current identity,
  session, Role, and Permission semantics remain intact.
- [ ] `AuthenticatedUserPrincipal` and `AuthenticatedAgentPrincipal` implement one immutable,
  framework-neutral authenticated-authority contract with named Permission and Role checks.
- [ ] The Agent implementation reports direct Permission presence and always reports `false` for Role presence;
  neither implementation decides consumer endpoint policy.
- [ ] A request-scoped, consumer-composed `CurrentSecurityContext` returns the selected authenticated authority and
  supplies the same delegated Permission and Role checks for the request lifetime.
- [ ] Tests prove User and Agent delegation, identity-specific snapshot access, request caching, absence/ambiguity
  failure behavior, rename compatibility decisions, and exact executable coverage.

## Verification

- Focused User-principal, Agent-principal, and current-security-context tests
- `./bin/planning-check`
- `./bin/build`
