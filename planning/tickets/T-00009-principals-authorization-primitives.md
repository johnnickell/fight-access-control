---
id: T-00009
title: Establish principals and authorization primitives
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00004
branch: feature/t-00009-principals-authorization-primitives
---

# Establish principals and authorization primitives

## Outcome

Application handlers can accept one immutable framework-neutral authenticated-principal snapshot and obtain a
current principal through a consumer-owned port, with role and permission primitives represented safely.

## Acceptance criteria

- [ ] Principal snapshots are immutable and contain no framework security-token dependency.
- [ ] The current-principal port supports per-request revalidation of account state, authentication version, ownership, and revocation.
- [ ] Role, Permission, and typed immutable view primitives preserve the accepted package boundary.
- [ ] Tests prove revalidation denial and absence of framework imports or production Adapter code.

## Exclusions

No framework security provider, middleware, request attribute, database query, or role-management UI.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
