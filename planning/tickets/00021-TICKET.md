---
id: T-00021
prd: PRD-00002
title: Manage direct Agent Permissions safely
status: ready-for-agent
blocked_by: T-00019
---

# Manage direct Agent Permissions safely

## Outcome

A maintainer can grant, revoke, or replace an Agent’s direct Permission assignments without duplicates, stale
updates, dangling Permission references, or partial changes. Safe Agent reads show assigned Permission identities
and names but never make an authorization decision.

## Scope

- In scope: direct Permission assignment operations, assignment revision, safe Agent read result, Agent repository
  support, and the shared Permission-removal reference fence in deterministic tests.
- Out of scope: Agent Roles, Permission-name policy, endpoint authorization, signed-request authentication,
  production persistence, database schema, and a general policy engine.

## Acceptance Criteria

- [ ] Grant, revoke, and complete-set replacement preserve a duplicate-free direct assignment set.
- [ ] Assignment revision advances only when assignments change; stale replacement fails with no partial change.
- [ ] Unknown Permissions, duplicate grants or replacement input, and revocation of an absent assignment fail safely.
- [ ] Permission removal fails while any Agent references that Permission, using the same authority fence as Roles.
- [ ] Safe reads expose only Agent lifecycle, credential metadata, assignment revision, and Permission IDs and names.
- [ ] Tests cover reference integrity, rollback behavior, safe reads, and exact coverage.

## Verification

- Focused Agent Permission aggregate, repository, and Application tests
- `./bin/planning-check`
- `./bin/build`
