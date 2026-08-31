# ADR 0005: Agent Direct Permission Assignment Revision

- Status: accepted
- Date: 2026-08-24

## Decision

An Agent owns a duplicate-free set of directly assigned `PermissionId` values. Its Domain operations are explicit:
grant one Permission, revoke one Permission, and replace the complete assignment set. Granting an already assigned
identifier and revoking an absent identifier are idempotent no-ops. Complete-set replacement normalizes repeated
identifiers because its input describes a desired set.

The Agent has a Permission-assignment revision that starts at `1` and advances only when its direct Permission
assignment changes. Complete-set replacement requires the caller's expected revision and fails closed when it is
stale. Replacing the current set with the same identities is an idempotent no-op: it does not write persistence,
advance the revision, or publish an `AgentPermissionsReplaced` event. The Permission-assignment revision is separate
from the credential revision established by ADR 0004, so credential lifecycle changes do not invalidate an
otherwise current assignment update.

Permission removal checks Agent assignments under the same consumer-owned transaction guard used for Role
assignments. An attempt to remove a Permission that is still assigned to an Agent fails with no partial change.
Agent assignment updates and Managed Policy reconciliation use that same guard, so a failed removal rolls back the
whole operation.

Unknown Permissions and stale assignment revisions fail with no partial change. An idempotent grant, revoke, or
complete-set replacement does not write persistence, advance the revision, or publish a success event. An Agent read
result exposes only its ID, lifecycle state,
credential ID and revision, assigned Permissions by ID and canonical name, and Permission-assignment revision.
It never exposes the shared secret or answers whether an action is allowed. User principal snapshots already carry
both Role and Permission IDs and canonical names for the same UI purpose.

## Consequences

The package can distinguish stale assignment replacement from credential rotation while retaining direct
Permission authority only, without leaking secrets or introducing a policy engine.
