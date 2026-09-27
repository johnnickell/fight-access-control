# Permission tier contract migration (v0.4.0 planning)

This is a consumer adoption note for the unreleased pre-1.0 Permission contract change in TASK-00041, not a package release or certification of any consumer schema.

`Permission::getTier()` and `PermissionView::getTier()` now return `PermissionTier`, never null. `PermissionView::toArray()` always includes a string `tier` (`ADMIN_SAFE` or `SUPER_ADMIN_ONLY`). `Permission::define()` creates a custom Permission with `ADMIN_SAFE`; `defineManaged()` and managed reconciliation retain the tier declared by the consumer. A custom Permission cannot be reconstructed as `SUPER_ADMIN_ONLY` or claimed by managed reconciliation; `getManagedTier()` remains restricted to managed Permissions.

Before consuming this contract, update persistence mappings, hydrators and read projections to store and restore a non-null tier, including for custom Permissions. Audit any code that interpreted a null tier as custom or treated it as eligible: use `isManaged()` for ownership and `getTier()` for classification. Reject or quarantine missing, invalid, or null legacy tier data instead of silently defaulting it to `ADMIN_SAFE`; resolve any stored data under the consumer's migration policy before deploying the new code. Update safe-array clients that expected `tier: null` for custom Permissions.

Tier classification alone does not authorize a caller. Custom-Role grants and direct Agent assignments enforce tier eligibility; the consumer remains responsible for caller and target authorization. Consumers own their database and adapter migrations and entry-point authorization. Fight Agent OS has no stored Permissions to migrate, but its schema and adapter adoption are not part of this package change. No tag or release is implied by this note.

## Super Admin Role and User Role commands (TASK-00042)

The exact `ROLE_SUPER_ADMIN` name is reserved for a managed Role. Reject or quarantine legacy custom Roles with
this name and conflicting duplicate Role IDs or names before upgrading; do not silently convert them to managed
Roles. Reconstitution of a custom Role under this name now fails. `RoleRepository` implementations must maintain
unique authoritative IDs and canonical names, reject ambiguous lookups, and preserve their role-reference fences.
A mismatched name/ID lookup cannot be treated as a designated Super Admin Role. Managed policy creation and
reconciliation must also respect these uniqueness constraints.

Remove the `RoleAdministrationAuthorization` argument from custom-Role create/rename/remove handler wiring (the
membership handlers no longer use it either). Remove the `UserRoleAssignmentAdministrationAuthorization`
argument from User Role assign/remove wiring; that interface and its authorization exception have been retired.
Keep actor IDs in Commands as provenance only. Protect **every** custom-Role lifecycle and User Role command entry
point, including CLI, worker, direct bus, and idempotent no-op calls, in the consumer application builder. The
builder decides caller authority, assignment vs removal policy, confirmation, audit, final-admin safeguards and
recovery. Do not rely on these handlers to authorize callers. Ownership-sensitive checks for sessions, email
changes, and invitation correction remain unchanged.

## Custom-Role Permission grants (TASK-00043)

Remove the final `RoleAdministrationAuthorization` binding from `GrantPermissionToCustomRoleHandler` and
`RevokePermissionFromCustomRoleHandler` constructors; the port and its exception have been retired. The builder
must protect these entry points, including no-op grants/revocations and direct bus invocations. Actor IDs remain
provenance, not authorization. A Super Admin caller cannot bypass the `ADMIN_SAFE` grant restriction. Rejected
Commands still publish safe failure evidence after rollback; successful changes publish events only after commit.

Role repository adapters must implement `validateCustomPermissionGrant(Permission $expected)` against current
Permission identity and `ADMIN_SAFE` tier under a transaction-duration fence shared with managed tier changes,
including no-op grants. `RoleRepository::add()` and `replace()` must reject custom-Role membership referencing
missing or protected Permissions under the same tier/reference fence; managed-Role membership is not restricted by
this custom-Role rule. The adapter chooses locking; a preflight read alone is not a concurrency guarantee. Consumer
persistence and builder wiring must be upgraded together. Managed-policy promotion and end-to-end promotion/grant
integration are separate TASK-00045 work. No consumer adoption or release is claimed.

## Direct Agent Permission assignments (TASK-00044)

Remove the `AgentPermissionAdministrationAuthorization` binding from `GrantPermissionToAgentHandler`,
`RevokePermissionFromAgentHandler`, and `ReplaceAgentPermissionsHandler` constructors. The port, its failure
exception, and the package test double have been retired. Actor IDs remain provenance, not authority; the
consumer builder must protect **all** entry points, including direct bus invocations and no-ops. Grant and
complete-set replacement accept only authoritative `ADMIN_SAFE` definitions, regardless of caller identity;
revocation retains ordinary desired-state semantics. Rejected commands emit safe failure evidence after rollback.

Agent repository adapters must implement `validatePermissionAssignments(array $expectedPermissions)` using the
current definition **identity** and tier under a transaction-duration reference/tier fence, including no-op
grants and replacements. `replacePermissionAssignments()` must reject any replacement containing missing or
protected Permissions and hold the same fence through commit. Share this fence with managed tier promotion. The
adapter chooses locking; a preflight lookup alone is insufficient. Consumer persistence and builder wiring must
be upgraded together. Managed-policy promotion and end-to-end interleaving proof belong to TASK-00045. No
consumer adoption or release is claimed.

## Protected managed reconciliation (TASK-00045)

A managed policy now rejects protected Permission membership anywhere except the authoritative managed Role
named exactly `ROLE_SUPER_ADMIN`. Managed-policy preview rejects invalid definitions and reports existing forbidden
membership on promotion; apply rechecks inside its transaction. To promote an existing managed Permission, first
remove any custom-Role or Agent assignments and reconcile ordinary managed-Role membership away while the Permission
is still `ADMIN_SAFE`. Do not expect the promotion to strip membership or to remove it in the same reconciliation.
A failed promotion rolls back the complete policy change and emits only the existing failure evidence.

Consumer adapters must implement `AgentRepository::hasPermissionAssignment()` using authoritative direct membership.
`PermissionRepository::replace()` must compare the expected Permission, and, on promotion to `SUPER_ADMIN_ONLY`,
atomically reject any custom-Role, ordinary managed-Role or Agent membership. Hold the same transaction-duration
reference/tier fence as `RoleRepository::validateCustomPermissionGrant()`, `RoleRepository::add()`/`replace()`,
`AgentRepository::validatePermissionAssignments()` and `replacePermissionAssignments()`, including idempotent
no-op grant paths. Managed Role writes must admit protected membership only for the authoritative managed
`ROLE_SUPER_ADMIN`. A preview read alone cannot fence concurrent grants; the concrete locking strategy and
PostgreSQL/consumer proof remain consumer-owned. Inject the Agent repository into `ManagedPolicyPlanner` for
promotion; an older composition lacking it rejects promotion closed. These changes are pre-1.0 public contract
changes, not a released tag, consumer adoption, or historical data migration.

The managed Super Admin Role may be assigned to a pending User for bootstrap. Assignment/removal otherwise retain
ordinary expected revisions, authoritative reference checks, no-op behavior, and post-commit events; only active
Users obtain authenticated principals. This note does not certify any consumer adoption or package release.
