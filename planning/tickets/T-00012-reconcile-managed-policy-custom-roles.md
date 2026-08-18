---
id: T-00012
title: Reconcile managed policy and custom roles
status: ready-for-agent
parent: PRD-00001
blocked_by:
  - T-00009
branch: feature/t-00012-reconcile-managed-policy-custom-roles
---

# Reconcile managed policy and custom roles

## Outcome

A maintainer can deterministically dry-run and atomically apply version-controlled Managed Permission and Role
definitions, while authorized super administrators can manage custom roles without rewriting managed policy.

## Acceptance criteria

- [ ] Managed definitions require stable UUIDs, uppercase names, tiers, and exact membership.
- [ ] Dry-run and apply preflight the same complete change set; apply is atomic.
- [ ] Managed roles remain runtime-immutable while authorized custom-role changes use existing permissions.
- [ ] Permission removal fails while code or live assignment references remain.
- [ ] Tests prove dry-run/apply parity, exact-membership removal, immutability, reference protection, and authorization.

## Exclusions

No configuration loader, ORM mapping, role-management UI, migration, or framework authorization integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
