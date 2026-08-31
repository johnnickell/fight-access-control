---
id: T-00026
prd: PRD-00003
title: Establish the unified authority contract
status: done
blocked_by:
---

# Establish the unified authority contract

## Outcome

A consumer can inspect an authenticated User or Agent through one framework-neutral authority contract, distinguish
its principal type, and observe one safe, immutable Permission snapshot shape without translating Agent-specific
authorization data.

## Scope

- In scope: authenticated-principal type, shared User and Agent authority behavior, one Principal Permission
  snapshot, exact Permission-definition resolution, value deduplication, safe serialization, internal-coordinator
  boundaries, and public-contract tests.
- Out of scope: collapsing signed Agent authentication and principal resolution into one flow, replacing the current
  security-context facade, framework identity wrappers, endpoint policy, production adapters, and consumer projects.

## Acceptance Criteria

- [x] The authenticated-authority contract exposes an explicit `USER` or `AGENT` principal type in addition to
  common Permission- and Role-presence checks.
- [x] Authenticated Users report their actual package Roles and Role-derived Permissions; authenticated Agents report
  direct Permissions and no package Roles.
- [x] User and Agent principals expose only the shared `PrincipalPermission` snapshot with stable Permission ID,
  canonical name, and the exact safe `permission_id` and `name` array representation.
- [x] Equivalent Permission entries are value-deduplicated, and the obsolete Agent-specific principal Permission
  snapshot is removed without a compatibility alias.
- [x] Package-owned exact Permission resolution rejects missing, unexpected, duplicated, or stale Permission
  definitions instead of returning a partial authority snapshot.
- [x] Exact resolution is shared by User and Agent authority construction, while its coordinator and other reusable
  package-owned service collaborators are final `@internal` implementation details rather than consumer extension
  interfaces; message handlers remain supported public application entry points.
- [x] Contract, resolution, public-boundary, and architecture tests prove the complete behavior with exact executable
  coverage and no framework or production Adapter dependency.

## Verification

- Focused authenticated-authority, principal-snapshot, exact-resolution, public-boundary, and architecture tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Delivered one framework-neutral authenticated-authority contract with stable User and Agent types, a shared safe
`PrincipalPermission` snapshot, exact fail-closed Permission resolution, and closed internal coordinators. Focused
public-boundary and architecture checks passed. `./bin/planning-check` passed with 37 records and 8 active;
`./bin/build` passed with 614 tests, 4,459 assertions, and exact 4,586/4,586 statement coverage.
