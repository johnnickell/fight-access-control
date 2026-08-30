---
id: T-00026
prd: PRD-00003
title: Establish the unified authority contract
status: ready-for-agent
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

- [ ] The authenticated-authority contract exposes an explicit `USER` or `AGENT` principal type in addition to
  common Permission- and Role-presence checks.
- [ ] Authenticated Users report their actual package Roles and Role-derived Permissions; authenticated Agents report
  direct Permissions and no package Roles.
- [ ] User and Agent principals expose only the shared `PrincipalPermission` snapshot with stable Permission ID,
  canonical name, and the exact safe `permission_id` and `name` array representation.
- [ ] Equivalent Permission entries are value-deduplicated, and the obsolete Agent-specific principal Permission
  snapshot is removed without a compatibility alias.
- [ ] Package-owned exact Permission resolution rejects missing, unexpected, duplicated, or stale Permission
  definitions instead of returning a partial authority snapshot.
- [ ] Exact resolution is shared by User and Agent authority construction, while its coordinator and other
  package-owned workflow coordinators are final `@internal` implementation details rather than consumer extension
  interfaces.
- [ ] Contract, resolution, public-boundary, and architecture tests prove the complete behavior with exact executable
  coverage and no framework or production Adapter dependency.

## Verification

- Focused authenticated-authority, principal-snapshot, exact-resolution, public-boundary, and architecture tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

Record the verified outcome only when terminal.
