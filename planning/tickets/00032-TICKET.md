---
id: T-00032
prd: PRD-00001
title: Prepare the v0.1.0 Release Candidate
status: done
blocked_by:
---

# Prepare the v0.1.0 Release Candidate

## Outcome

Produce one reviewable `v0.1.0` release candidate on `release/0.1.0` with accurate package, changelog, and
planning surfaces, bound to fresh repository-owned quality evidence before any external publication effect.

## Scope

- In scope: release notes, release-facing documentation, planning-state reconciliation, exact candidate
  verification, and the canonical local quality gates.
- Out of scope: production-code or public-API changes, signing-key custody, tag creation or push, GitHub Release
  publication, maintenance-branch publication, Packagist submission, and cleanup.

## Acceptance Criteria

- [x] `CHANGELOG.md` records the complete `0.1.0` capability milestone in Keep a Changelog format with canonical
      `v0.1.0` links.
- [x] README and planning projections accurately describe the delivered package, the first `0.x` release, and the
      separate pre-`1.0.0` stability decision.
- [x] PRD-00001 records package-first release sequencing: full starter implementation follows tagged Fight
      AccessControl and Fight Common versions and may drive later `0.x` compatibility releases.
- [x] Stale PRD-00003, PRD-00004, and EPIC-00003 status projections are reconciled with their terminal tickets.
- [x] The release candidate changes no production source, Composer dependency, namespace, or Adapter boundary.
- [x] `./bin/planning-check` and `./bin/build` pass from the isolated release worktree.

## Verification

- Review the exact changed-file set and release notes, then run `./bin/planning-check` and `./bin/build` from the
  isolated `release/0.1.0` worktree. External publication begins only after the reviewed candidate is merged to
  `main` and its exact merge identity is reverified.

## Completion Notes

Completed on 2026-09-10. The release-preparation slice converted the changelog to Keep a Changelog format,
documented `v0.1.0` as the first public package milestone, and reconciled the terminal PRD-00003, PRD-00004, and
EPIC-00003 projections. A later release-sequencing decision clarified that full starter implementation follows
tagged Fight AccessControl and Fight Common versions rather than gating `v0.1.0`; downstream compatibility findings
may produce subsequent pre-`1.0.0` releases. No production or Composer contract changed. The canonical build passed
with 607 tests, 4,675 assertions, exact 4,609/4,609 statement coverage, valid documentation links, and a successful
production-only Composer installation. Tagging, GitHub publication, maintenance-branch publication, Packagist
submission, and cleanup remain separate effects.
