---
id: T-00012
title: Reconcile managed policy and custom roles
status: done
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

- [x] Managed definitions require stable UUIDs, uppercase names, `ADMIN_SAFE` or `SUPER_ADMIN_ONLY` Permission
  tiers, and exact Role permission membership.
- [x] Dry-run and apply preflight the same complete change set; apply is atomic.
- [x] Managed roles remain runtime-immutable while authorized custom-role changes use existing Permissions.
- [x] Permission removal fails while code or live assignment references remain.
- [x] Explicit role-assignment mutation commands authorize the acting principal and atomically update User RoleId
  assignment state without permitting dangling Role references.
- [x] Tests prove dry-run/apply parity, exact-membership removal, managed immutability, Permission-tier enforcement,
  reference protection, custom-role mutation, and assignment-command authorization denial.

## Exclusions

No configuration loader, ORM mapping, role-management UI, migration, or framework authorization integration.

## Verification

- `./bin/phpunit`
- `./bin/planning-check`
- `./bin/build`

## Delivery Evidence

- Immutable managed Permission and Role definitions validate stable identifiers, canonical uppercase names,
  `ADMIN_SAFE` or `SUPER_ADMIN_ONLY` tiers, duplicate-free exact membership, and deterministic serialization.
  `PreviewManagedPolicyHandler` and `ReconcileManagedPolicyHandler` share one User-assignment-aware planner, so the
  same persisted snapshot produces the same complete ordered preflight and rejects assigned managed-Role removal.
- Reconciliation runs inside one transactional Unit of Work, preserves custom records, reconciles exact managed
  membership, fails closed on stale state or code/live references, and publishes `ManagedPolicyReconciled` only
  after commit. The event validates and canonicalizes its exact nested serialized plan schema.
- Explicit authorized custom-role create, rename, grant, revoke, and removal commands keep managed Roles immutable
  and use only authoritative existing Permissions. Explicit User assignment commands mutate aggregate-owned RoleId
  state and its revision without admitting dangling Role references.
- Permission, Role, and User repository contracts retain canonical aggregate methods while requiring consumer
  adapters to validate references and mutate under one transaction-duration persistence fence. Race and rollback
  tests prove Permission-Role and Role-User authority losses fail closed at the final write boundary.
- Final `./bin/planning-check` passed. Final `./bin/build` passed 491 tests with 3,464 assertions and exact statement
  coverage at 3,593/3,593; Composer validation, syntax, PHPCS, PHPStan, architecture, package boundaries, Rector,
  documentation, and production autoload checks passed. Independent Standards and Spec reviews reported no
  blocking findings or scope creep.
