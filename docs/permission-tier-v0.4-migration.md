# Permission tier contract migration (v0.4.0 planning)

This is a consumer adoption note for the unreleased pre-1.0 Permission contract change in TASK-00041, not a package release or certification of any consumer schema.

`Permission::getTier()` and `PermissionView::getTier()` now return `PermissionTier`, never null. `PermissionView::toArray()` always includes a string `tier` (`ADMIN_SAFE` or `SUPER_ADMIN_ONLY`). `Permission::define()` creates a custom Permission with `ADMIN_SAFE`; `defineManaged()` and managed reconciliation retain the tier declared by the consumer. A custom Permission cannot be reconstructed as `SUPER_ADMIN_ONLY` or claimed by managed reconciliation; `getManagedTier()` remains restricted to managed Permissions.

Before consuming this contract, update persistence mappings, hydrators and read projections to store and restore a non-null tier, including for custom Permissions. Audit any code that interpreted a null tier as custom or treated it as eligible: use `isManaged()` for ownership and `getTier()` for classification. Reject or quarantine missing, invalid, or null legacy tier data instead of silently defaulting it to `ADMIN_SAFE`; resolve any stored data under the consumer's migration policy before deploying the new code. Update safe-array clients that expected `tier: null` for custom Permissions.

Tier classification alone does not authorize a caller or enforce custom Role/Agent grant eligibility. Those checks are separate TASKs. Consumers own their database and adapter migrations and entry-point authorization. Fight Agent OS has no stored Permissions to migrate, but its schema and adapter adoption are not part of this package change. No tag or release is implied by this note.

## Super Admin Role and User Role commands (TASK-00042)

The exact `ROLE_SUPER_ADMIN` name is reserved for a managed Role. Reject or quarantine legacy custom Roles with
this name and conflicting duplicate Role IDs or names before upgrading; do not silently convert them to managed
Roles. Reconstitution of a custom Role under this name now fails. `RoleRepository` implementations must maintain
unique authoritative IDs and canonical names, reject ambiguous lookups, and preserve their role-reference fences.
A mismatched name/ID lookup cannot be treated as a designated Super Admin Role. Managed policy creation and
reconciliation must also respect these uniqueness constraints.

Remove the `RoleAdministrationAuthorization` argument from custom-Role create/rename/remove handler wiring (the
membership handlers still need it until TASK-00043). Remove the `UserRoleAssignmentAdministrationAuthorization`
argument from User Role assign/remove wiring; that interface and its authorization exception have been retired.
Keep actor IDs in Commands as provenance only. Protect **every** custom-Role lifecycle and User Role command entry
point, including CLI, worker, direct bus, and idempotent no-op calls, in the consumer application builder. The
builder decides caller authority, assignment vs removal policy, confirmation, audit, final-admin safeguards and
recovery. Do not rely on these handlers to authorize callers. Ownership-sensitive checks for sessions, email
changes, and invitation correction remain unchanged.

The managed Super Admin Role may be assigned to a pending User for bootstrap. Assignment/removal otherwise retain
ordinary expected revisions, authoritative reference checks, no-op behavior, and post-commit events; only active
Users obtain authenticated principals. This note does not certify any consumer adoption or package release.
