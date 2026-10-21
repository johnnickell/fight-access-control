# Role caller revisions

Current-contract capability published in [v0.8.0](release-0.8.0.md), not part of v0.7.0.
Role revisions identify state, not authority. Consumers continue to protect
all HTTP, CLI, worker and direct command-bus entry points, including no-ops, and own actor provenance, target/tenant
scope, last-admin policy, destructive confirmation, response mapping and safe logs under
[ADR 0010](../planning/adr/0010-permission-eligibility-and-caller-authorization.md).

## Displayed state through dispatch

Both `GetRoleById` and `ListRoles` return `RoleView` with required `revision` and typed `getRevision(): RoleRevision`.
Keep that exact value with the Role state displayed for editing or removal confirmation. `RenameCustomRole` now
requires `(actorId, roleId, name, expectedRevision)`; `RemoveCustomRole` requires
`(actorId, roleId, expectedRevision)`. Their named `getExpectedRevision()` and canonical `expected_revision` string
field carry the same value. Parse it with `RoleRevision::fromString()`, never derive it from a later storage read.
Missing, non-string and malformed values reject without fallback.

A canonical revision looks like `11111111-1111-4111-8111-111111111111:1`: a lowercase random UUID-v4 incarnation,
a colon and an unpadded decimal sequence from **1 through 999999999**. Treat it as one opaque, case-sensitive string
in transports/UI. OpenAPI `Fight.AccessControl.RoleRevision` describes the same grammar and bounds; the Role View,
rename and removal components reference it. Collection and optional JSend success composition remain unchanged.
No endpoint, transport status or error envelope is supplied.

A matching token permits only an otherwise legal operation. Missing Roles, managed/reserved identities, colliding
names, invalid membership and live references still reject. A stale token, including a stale same-name request,
raises safe typed `RoleConflictException` (a `CustomRoleException`). A lost final expected-state/reference fence also
raises that type; the preliminary comparison does not replace final atomic admission. On conflict, refresh the
current Role, display the new state and obtain fresh intent/confirmation. **Never silently substitute a newer token
or automatically retry stale intent.** A token is neither a credential nor permission.

A valid same-name rename validates/fences the complete current snapshot without writing, changing timestamps or
revision, or publishing success. Membership desired-state retries retain their existing no-write/no-success behavior
and required Permission/reference guards; they have no new caller-token input. Every real custom rename or
Permission grant/revoke advances exactly once. Managed creation/reconciliation/removal uses the same incarnation
and revision rules. Unchanged managed definitions retain their state; the existing policy-level reconciliation
notification semantics are unchanged. Reads and User Role assignments do not advance Role state.

## Persistence and all-writer fences

Creation factories `Role::define()` and `defineManaged()` establish a fresh random incarnation at sequence one.
Persist the full token alongside ID, canonical name, ownership, Permission set and complete timestamps. Hydrate through
`Role::reconstitute(id, name, permissionIds, managed, createdAt, updatedAt, revision)`, constructing the required
`RoleRevision` from its stored value. An equivalent adapter subclass uses the required protected constructor value.
Never call a creation factory, generate an incarnation, infer a sequence from timestamps, or default missing state
while hydrating. Same-clock edits and name/membership ABA must retain their advancing sequence. Recreation of the
same Role ID starts a **new** random incarnation; never reuse a retired incarnation. Sequence exhaustion rejects a
real change before persistence, without partial state or wraparound. No-ops and otherwise legal removal remain
possible at the maximum; do not reset or replace the incarnation to continue editing an exhausted Role.

Every adapter implements the current mandatory `RoleRepository::validateCurrent(expectedRole)` as a non-writing,
complete-state atomic validation held through transaction completion. `replace(expected, replacement)` and
`remove(expected)` still compare the complete predecessor, now including incarnation/revision, not PHP object
identity, collection iterator internals, an ORM snapshot or `updated_at` alone. `Role::hasSameState()` compares the
complete persisted business state by value; it is useful for adapters but grants no fence by itself.
Replacement preserves ID, ownership, creation time and incarnation,
and advances sequence exactly once for a real name/membership change. A no-op must not become a storage write.
Do not allow direct repository paths to reset or skip revisions.

Use the same actual connection/Unit of Work and consistent lock order across custom creation/rename/removal,
Permission grant/revoke, managed creation/reconciliation/removal, Permission tier/reference changes and User reference
admission. Name uniqueness and reserved Super Admin identity remain authoritative. Hold identity/name, tier and
reference fences until commit or rollback, including non-writing same-name admission. Removal must reject references
created by pending registration as well as other User assignments. `UserRepository::addWithRoleAssignments()` compares
complete resolved Roles **including revision**, so even an otherwise identical revision-only mismatch rolls back
registration's pending User, assignments, grant/delivery and required audit. A prior query or startup check supplies
neither reservation nor transaction admission.

## Outcomes and verification

Purpose-specific success events follow confirmed commit only. Pre-commit conflict/persistence failure rolls back;
post-commit publication failure still throws with committed state retained. Coarse failure notification is best
effort and cannot replace the primary throwable. Reconcile lost/uncertain outcomes from authoritative state under
current authorization, not from the thrown exception alone. Role operations do not inherit the Agent issuance
warning-return exception. See the consolidated [operation-outcome guide](operation-outcomes.md).

Consumer-bindable `tests/Application/AccessControl/Role/Repository/RoleRevisionConformance.php` provides unchanged
assertions through four protected hooks:

- `repository()`: isolated current Role persistence on the binding's actual connection.
- `permission()`: persist an authoritative `ADMIN_SAFE` Permission on that composition and return its ID.
- `transaction(callable)`: commit successful operations; roll back and rethrow failures on the shared Unit of Work.
- `restart(repository)`: reopen persisted state and reconstruct all values without reissuing/resetting authority;
  rebind subsequent transactions to the reopened connection.

It checks hydrated complete-state CAS, revision-only name/membership ABA, same-ID recreation, stale removal/validation
and transaction rollback. The package binding uses fresh modeled state/Unit of Work and validated reconstitution;
it is not a database/process test. Owning handler suites add stale/fresh/no-op intent, post-read CAS loss, held
no-op fences, membership/policy writers, registration both-winner schedules, safe notifications, uncertain commits
and publication failures. Generated-schema tests run in the default gate.

Consumers must independently qualify their actual row mapping, database isolation/locks, every writer and both
concurrent winner orders (including registration/reference admission versus rename/removal), rollback, uncertain
commit and physical restart. Also qualify exact displayed-token propagation, refresh/reconfirmation, all entry-point
permissions and response/log sanitation. Package tests do not prove those consumer obligations or authorize adoption,
schema deployment, release or production deployment. These breaking pre-v1 API/persistence changes have no historical
reader, default, migration or backfill path under [ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md).
