---
id: T-00021
prd: PRD-00002
title: Manage direct Agent Permissions safely
status: done
blocked_by: T-00019,T-00025
---

# Manage direct Agent Permissions safely

## Outcome

A maintainer can grant, revoke, or replace an Agent’s direct Permission assignments without duplicates, stale
updates, dangling Permission references, or partial changes. Safe Agent reads show assigned Permission identities
and names but never make an authorization decision.

## Scope

- In scope: direct Permission assignment operations, assignment revision, safe Agent read result including the
  required operator-facing Agent name from T-00025, Agent repository support, and the shared Permission-removal
  reference fence in deterministic tests.
- Out of scope: Agent Roles, Permission-name policy, endpoint authorization, signed-request authentication,
  production persistence, database schema, and a general policy engine.

## Acceptance Criteria

- [x] Grant, revoke, and complete-set replacement preserve a duplicate-free direct assignment set.
- [x] Assignment revision advances only when assignments change; stale replacement fails with no partial change.
- [x] Unknown Permissions, duplicate grants or replacement input, and revocation of an absent assignment fail safely.
- [x] Permission removal fails while any Agent references that Permission, using the same authority fence as Roles.
- [x] Safe reads expose only Agent name, lifecycle, credential metadata, assignment revision, and Permission IDs and
  names.
- [x] Tests cover reference integrity, rollback behavior, safe reads, and exact coverage.

## Verification

- Focused Agent Permission aggregate, repository, and Application tests
- `./bin/planning-check`
- `./bin/build`

## Completion Notes

- Added explicit grant, revoke, and complete-set replacement commands with a separate Permission-assignment revision,
  duplicate and stale-update rejection, one transactional repository boundary, and post-commit success events.
- Extended the shared adapter-owned Permission-reference fence so live Role or Agent assignments block removal, and
  proved late-reference managed-policy reconciliation rolls back every partial change.
- Added paginated and by-ID secret-free Agent reads containing the operator-facing name, lifecycle and credential
  metadata, assignment revision, and exact Permission ID/name snapshots only.
- Two-axis review found and closed credential-replacement and revision-only repository bypasses; the final Standards
  review had no blocking findings and the final Spec review had no findings.
- `./bin/planning-check` passed with 28 records and 5 active records. `./bin/build` passed with 574 tests,
  4,242 assertions, exact 4,437/4,437 statement coverage, and every complete quality gate green.
