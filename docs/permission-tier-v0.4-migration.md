# Permission tier contract migration (v0.4.0 planning)

This is a consumer adoption note for the unreleased pre-1.0 Permission contract change in TASK-00041, not a package release or certification of any consumer schema.

`Permission::getTier()` and `PermissionView::getTier()` now return `PermissionTier`, never null. `PermissionView::toArray()` always includes a string `tier` (`ADMIN_SAFE` or `SUPER_ADMIN_ONLY`). `Permission::define()` creates a custom Permission with `ADMIN_SAFE`; `defineManaged()` and managed reconciliation retain the tier declared by the consumer. A custom Permission cannot be reconstructed as `SUPER_ADMIN_ONLY` or claimed by managed reconciliation; `getManagedTier()` remains restricted to managed Permissions.

Before consuming this contract, update persistence mappings, hydrators and read projections to store and restore a non-null tier, including for custom Permissions. Audit any code that interpreted a null tier as custom or treated it as eligible: use `isManaged()` for ownership and `getTier()` for classification. Reject or quarantine missing, invalid, or null legacy tier data instead of silently defaulting it to `ADMIN_SAFE`; resolve any stored data under the consumer's migration policy before deploying the new code. Update safe-array clients that expected `tier: null` for custom Permissions.

Tier classification alone does not authorize a caller or enforce custom Role/Agent grant eligibility. Those checks are separate TASKs. Consumers own their database and adapter migrations and entry-point authorization. Fight Agent OS has no stored Permissions to migrate, but its schema and adapter adoption are not part of this package change. No tag or release is implied by this note.
