# Wayfinder Map: Enforce permission grant tiers for v0.4.0

**Label:** `wayfinder:map`
**Status:** Active

> This map indexes decisions. Each decision belongs to its linked ticket; no proposal below is an accepted policy.

## Destination

Produce a decision-complete Fight AccessControl handoff for a proposed `v0.4.0` package release that enforces
protected Permission membership at package-owned command boundaries, rejects unsafe managed Permission
reclassification, and states the consumer authorization obligations. The handoff must identify
the package's current direct Agent Permission path as well as Role and User-role paths.

**Done** = every linked decision is closed, remaining fog is resolved or excluded, and the map links to the resulting
EPIC, TICKET, and/or implementation TASKs. No implementation or release is authorized by this map.

## Notes

- Source baseline: clean `develop` at `9a08c0b` before this map was authored on 2026-09-25; latest local release
  tag `v0.3.0`. The inspected `src/` and `composer.json` have no committed delta from that tag. Recheck source,
  branch, and release before resolving a later ticket. The Fight Agent OS canary note is a proposal, not policy.
- Current `PermissionTier` classifies managed Permissions as `ADMIN_SAFE` or `SUPER_ADMIN_ONLY`; custom Permissions
  have a null tier. Current Role membership stores Permission IDs, and managed definitions supply stable Role IDs.
- `GrantPermissionToCustomRoleHandler` and `AgentPermissionAssignmentCoordinator` check existence but not tier.
  User-role assignment/removal and custom-role membership use actor-only authorization ports. The package currently
  has no direct User Permission grant, but does have direct Agent Permission grant and complete-set replacement.
- `ManagedPolicy` validates references and uniqueness but does not constrain protected tier membership. Reconciliation
  plans and applies within a Unit of Work. Existing repository reference fences address removal races, not every
  tier, actor-authority, or policy-revision race. These are observations to investigate, not an approved design.
- The closed [protected-membership decision](tickets/WF-011-protected-permission-membership.md) assigns managed
  Permission naming and tier classification to the consuming project. The package enforces the declared tier,
  recognizes managed `ROLE_SUPER_ADMIN` by exact name, and treats `User` as the human principal type.
- Use the local [planning conventions](../CONVENTIONS.md), project profile, `CONTEXT.md`, and relevant Fight standards.
  Each decision is HITL grilling; do not create implementation records until the map is settled.

## Decisions so far

1. **[Define protected Permission membership and designated Role identity](tickets/WF-011-protected-permission-membership.md) is settled.** A managed `SUPER_ADMIN_ONLY` Permission belongs only to the exact-name managed `ROLE_SUPER_ADMIN` and reaches only a human `User` through that Role. The consumer declares managed Permission names and tiers; the package enforces declared tiers and never grants protected authority to an Agent, custom Role, other managed Role, or direct User assignment.
2. **[Classify custom Permissions for delegation](tickets/WF-012-custom-permission-delegation.md) is settled.** Every Permission has a non-null tier; a custom Permission starts `ADMIN_SAFE` and cannot become protected. `ADMIN_SAFE` allows controlled Role and direct Agent delegation; caller authorization belongs to the consumer. Fight Agent OS has no stored Permissions to migrate, though its schema and adapter require changes.
3. **[Separate Permission eligibility from caller authorization](tickets/WF-013-target-aware-role-administration.md) is settled.** AccessControl enforces tier and managed-Role invariants in Role and Agent Permission commands. Application builders protect every command entry point using suitable controls; v0.4.0 removes actor-only checks from Role administration, Agent Permission, and User Role-assignment handlers without adding a target-aware port. Target-specific checks for sessions, email changes, and invitation correction remain. Direct command-bus access requires consumer-owned protection.
4. **[Set Super Admin assignment and removal guarantees](tickets/WF-014-super-admin-role-elevation.md) is settled.** Only the managed Role may use the exact `ROLE_SUPER_ADMIN` name; custom-role creation, rename, and inconsistent reconstruction must not impersonate it. Package User Role commands otherwise use ordinary assignment/removal semantics, including pending User assignment for bootstrap. The application builder owns caller authority, confirmation, audit, removal policy, last-admin protection, and recovery.
5. **[Guard managed Permission reclassification](tickets/WF-015-forbidden-authority-remediation.md) is settled.** Reconciliation rejects promotion to `SUPER_ADMIN_ONLY` while a custom Role, ordinary managed Role, or Agent still holds the Permission; it does not change policy partially or strip memberships automatically. Fight Agent OS has no stored Permissions, so this map plans no historical cleanup migration.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-011 | [Define protected Permission membership and designated Role identity](tickets/WF-011-protected-permission-membership.md) | Grilling | HITL | **Closed** | — |
| WF-012 | [Classify custom Permissions for delegation](tickets/WF-012-custom-permission-delegation.md) | Grilling | HITL | **Closed** | WF-011 |
| WF-013 | [Separate Permission eligibility from caller authorization](tickets/WF-013-target-aware-role-administration.md) | Grilling | HITL | **Closed** | WF-011, WF-012 |
| WF-014 | [Set Super Admin assignment and removal guarantees](tickets/WF-014-super-admin-role-elevation.md) | Grilling | HITL | **Closed** | WF-011 |
| WF-015 | [Guard managed Permission reclassification](tickets/WF-015-forbidden-authority-remediation.md) | Grilling | HITL | **Closed** | WF-011 |
| WF-016 | [Set atomic enforcement and integration proof](tickets/WF-016-atomic-enforcement-and-proof.md) | Grilling | HITL | **Open** | WF-013, WF-014, WF-015 |

## Blocking relationships

```text
Protected membership ──→ Custom Permission classification ──→ Eligibility/authorization boundary ─────┐
         ├──────────────→ Super Admin assignment and removal ──────────────────────────────────────┼─→ Atomic enforcement and proof ──→ Handoff
         └──────────────→ Managed Permission reclassification ──────────────────────────────────────┘
```

## Frontier

[Set atomic enforcement and integration proof](tickets/WF-016-atomic-enforcement-and-proof.md)
is the next authored frontier.

## Not yet specified (fog)

- Exact adapter-level locking mechanism and compatibility sequence, pending WF-016.

## Out of scope

- Runtime implementation, vendor patches, a Fight Agent OS dependency change, a package tag/release, publication,
  and deployment during Wayfinder.
- Creating a scoped Workspace/Repository authority model in this package; application builders own any scope
  authorization at their command entry points.
- Exact consumer confirmation, audit, removal, and last-admin recovery protocols, including any Agent OS
  implementation planning outside this map.
- Historical data cleanup; the first implementing consumer has no stored Permissions to migrate.
