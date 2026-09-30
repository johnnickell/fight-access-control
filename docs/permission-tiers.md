# Current Permission tier contract

Only the current pre-v1 contract is supported under
[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md). Consumers own persistence, composition and entry-point
authorization. This guide does not define historical conversions, migrations or a consumer adoption result.

`Permission::getTier()` and `PermissionView::getTier()` return non-null `PermissionTier`; safe arrays contain
`ADMIN_SAFE` or `SUPER_ADMIN_ONLY`. Custom `Permission::define()` creates `ADMIN_SAFE`. Managed definitions and
reconciliation retain their declared tier. A custom Permission cannot become protected or be claimed by managed
reconciliation; `getManagedTier()` is restricted to managed Permissions. Hydrators restore valid non-null tier and
ownership, never default unknown state to an eligible Permission.

## Authority and the Super Admin Role

Exactly `ROLE_SUPER_ADMIN` is reserved for the authoritative managed Role. Custom Roles cannot use that name.
`RoleRepository` maintains unique IDs and canonical names and rejects ambiguous/mismatched lookups. Only the
managed Super Admin Role can hold protected Permissions. Agents have direct `ADMIN_SAFE` Permissions, not Roles.

The consumer builder protects every custom-Role lifecycle, User Role assignment and Agent Permission command entry
point, including CLI, workers, direct bus and no-ops. Actor IDs are provenance, not authority. Last-admin safeguards,
caller/target policy and recovery belong to that builder; the retired actor-only authorization ports are not supplied.
Ownership-sensitive package authorization for sessions, email changes and invitation correction remains distinct.
A Super Admin caller cannot bypass the package's `ADMIN_SAFE` restriction for custom-Role grants or direct Agents.

## Repository fences

`RoleRepository::validateCustomPermissionGrant()` checks the exact authoritative Permission identity and tier,
including no-op grants, under a transaction-duration reference/tier fence. Custom Role `add()`/`replace()` reject
missing or protected membership. `AgentRepository::validatePermissionAssignments()` and
`replacePermissionAssignments()` enforce the same rules for the complete Agent assignment set. Share these fences
with managed tier changes; a preflight read alone cannot qualify concurrency safety.

Managed policy preview rejects invalid protected membership and reports forbidden assignments on promotion.
Apply rechecks inside its transaction. To promote a managed Permission, first remove custom-Role/Agent and ordinary
managed-Role memberships while it is `ADMIN_SAFE`. Promotion does not strip memberships. `PermissionRepository::replace()`
compares the expected state and atomically rejects promotion while forbidden references remain. Managed Role writers
admit protected membership only for the authoritative managed Super Admin Role. A failed apply rolls back all policy
writes and publishes only failure evidence.

`ManagedPolicyPlanner` requires the Agent repository for authoritative direct membership checks. Consumer repositories
must qualify these fences with real independent writer races; modeled package tests are not PostgreSQL qualification.
The managed Super Admin Role can be assigned to a pending User for bootstrap; only active Users authenticate.
Assignments/removals preserve expected revisions, reference checks, desired-state no-ops and post-commit events.
