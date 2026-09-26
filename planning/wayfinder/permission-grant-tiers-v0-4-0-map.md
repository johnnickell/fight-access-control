# Wayfinder Map: Enforce permission grant tiers for v0.4.0

**Label:** `wayfinder:map`
**Status:** Active

> This map indexes decisions. Each decision belongs to its linked ticket; no proposal below is an accepted policy.

## Destination

Produce a decision-complete Fight AccessControl handoff for a proposed `v0.4.0` package release that enforces
protected Permission membership and target-aware administration at package-owned command boundaries, detects and
addresses existing invalid authority, and states the consumer integration obligations. The handoff must identify
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

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-011 | [Define protected Permission membership and designated Role identity](tickets/WF-011-protected-permission-membership.md) | Grilling | HITL | **Closed** | — |
| WF-012 | [Classify custom Permissions for delegation](tickets/WF-012-custom-permission-delegation.md) | Grilling | HITL | **Open** | WF-011 |
| WF-013 | [Authorize custom-role Permission changes by target and scope](tickets/WF-013-target-aware-role-administration.md) | Grilling | HITL | **Open** | WF-011, WF-012 |
| WF-014 | [Set Super Admin assignment and removal guarantees](tickets/WF-014-super-admin-role-elevation.md) | Grilling | HITL | **Open** | WF-011 |
| WF-015 | [Detect and remedy historical forbidden authority](tickets/WF-015-forbidden-authority-remediation.md) | Grilling | HITL | **Open** | WF-011 |
| WF-016 | [Set atomic enforcement and integration proof](tickets/WF-016-atomic-enforcement-and-proof.md) | Grilling | HITL | **Open** | WF-013, WF-014, WF-015 |

## Blocking relationships

```text
Protected membership ──→ Custom Permission classification ──→ Target-aware Role administration ──┐
         ├──────────────→ Super Admin assignment and removal ──────────────────────────────────────┼─→ Atomic enforcement and proof ──→ Handoff
         └──────────────→ Historical authority remediation ────────────────────────────────────────┘
```

## Frontier

[Classify custom Permissions for delegation](tickets/WF-012-custom-permission-delegation.md) is the next authored
frontier. [Set Super Admin assignment and removal guarantees](tickets/WF-014-super-admin-role-elevation.md) and
[Detect and remedy historical forbidden authority](tickets/WF-015-forbidden-authority-remediation.md) are also
unblocked after WF-011; they remain separate sessions.

## Not yet specified (fog)

- Exact consumer confirmation and audit protocol shape, pending the elevation/removal decision and current Agent OS
  integration evidence.
- Exact adapter-level locking mechanism and migration sequence, pending the authority/remediation decisions.

## Out of scope

- Runtime implementation, vendor patches, a Fight Agent OS dependency change, a package tag/release, publication,
  and deployment during Wayfinder.
- Creating a scoped Workspace/Repository authority model in `v0.4.0` without a separate decision; this map must
  state how to fail closed until such a model exists.
