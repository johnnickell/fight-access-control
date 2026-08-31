---
id: T-00009
prd: PRD-00001
title: Establish principals and authorization primitives
status: done
blocked_by: T-00004
---

# Establish principals and authorization primitives

## Outcome

Application handlers can obtain one immutable framework-neutral authenticated-principal snapshot from
authoritative package state while consumers supply only the current request's authentication context.

## Acceptance Criteria

- [x] Foundational `Role` and `Permission` aggregates own stable IDs and validated names; Role permission membership
  is duplicate-free, HashSet-backed, and read-only in this slice.
- [x] User authority owns duplicate-free, HashSet-backed RoleId assignment state required for authoritative
  principal resolution, without introducing role-assignment mutation commands.
- [x] `AuthenticationContext` is immutable and contains only UserId, refresh-session ID, and authentication version;
  it contains no roles, permissions, framework token, or request object.
- [x] A consumer-owned no-argument `AuthenticationContextProvider` supplies only the current request's
  `AuthenticationContext`; a package-owned request-scoped `CurrentPrincipalProvider` resolves it authoritatively
  once and caches the result for Application handlers.
- [x] Resolution fails closed unless the User is active, the session exists and is usable, ownership and
  authentication versions match, and every assigned RoleId and PermissionId resolves authoritatively.
- [x] `AuthenticatedPrincipal` is an immutable read model containing the resolved identity, session, authentication
  version, Roles, and Permissions needed by Application handlers.
- [x] Role and Permission repositories provide `getByIds()` and `getAll(Pagination): ResultSet` contracts, with
  typed results and no persistence or framework dependency.
- [x] Tests prove successful resolution, duplicate-free relationships, every revalidation denial, missing-reference
  denial, immutable typed views, and absence of framework imports or production Adapter code.

## Scope

### Out of Scope

No framework security provider, middleware, request attribute, database query, role/permission mutation,
Permission tier, managed-policy reconciliation, assignment command, or role-management UI.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- `AuthenticationContextProvider` is the only consumer principal-composition port and has one no-argument method
  returning the identity/session/version-only `AuthenticationContext`; no principal, Role, or Permission snapshot
  enters through its API.
- The package `CurrentPrincipalProvider` is documented for per-request composition, invokes
  `AuthoritativePrincipalResolver` on first access, and caches only that authoritative result for subsequent handler
  access in the same request. Tests prove one context read and one authoritative repository resolution.
- User RoleId authority is duplicate-free and HashSet-backed. `replaceRoleAssignments()` atomically replaces the
  complete set, advances the assignment revision exactly once only when the final set changes, and preserves all
  authentication state. Element-level assignment methods and commands remain deferred to T-00012.
- Resolver tests cover active User/session/version/ownership requirements and fail closed for revoked, expired,
  missing, incomplete, or unexpected Role and Permission authority. Role names retain the approved
  `/^ROLE_[A-Z_]+$/` validation rule.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 278 tests with 1,722 assertions and exact statement
  coverage at 1,689/1,689; PHPCS, PHPStan, architecture, package boundaries, Rector, documentation, and production
  autoload checks passed.
