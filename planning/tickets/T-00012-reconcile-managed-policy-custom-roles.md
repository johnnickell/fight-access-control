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
definitions over the T-00009 foundations, while authorized super administrators can manage custom roles and User
role assignments without rewriting managed policy.

## Acceptance criteria

- [ ] Managed definitions require stable UUIDs, uppercase names, `ADMIN_SAFE` or `SUPER_ADMIN_ONLY` Permission
  tiers, and exact Role permission membership.
- [ ] Dry-run and apply preflight the same complete change set; apply is atomic.
- [ ] Managed roles remain runtime-immutable while authorized custom-role changes use existing Permissions.
- [ ] Permission removal fails while code or live assignment references remain.
- [ ] Explicit role-assignment mutation commands authorize the acting principal and atomically update User RoleId
  assignment state without permitting dangling Role references.
- [ ] Tests prove dry-run/apply parity, exact-membership removal, managed immutability, Permission-tier enforcement,
  reference protection, custom-role mutation, and assignment-command authorization denial.

## Exclusions

No configuration loader, ORM mapping, role-management UI, migration, or framework authorization integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`
