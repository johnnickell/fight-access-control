# Minimal Agent profiles and managed Permissions (v0.5.0)

[TASK-00070](../planning/tasks/00070-TASK.md) supplies a framework-neutral read and reusable Permission definitions.
The read/definition module supplies no transport, administrator endpoint, consumer seed/migration, grants or runtime
wiring. [Self-service profile Tools](agent-profile-tools.md) now bind this read and the separate generic
[UpdateAgent name operation](agent-name-updates.md) through protected MCP composition.

## Read contract

Register Application `Agent\QueryHandler\GetAgentProfileHandler` with the consumer's Domain `AgentRepository`.
Its `queryRegistration()` names Domain `Agent\Query\GetAgentProfile`; dispatch through Fight Common's Query contract:

```php
use Fight\AccessControl\Domain\AccessControl\Agent\Query\GetAgentProfile;
use Fight\Common\Domain\Messaging\Query\QueryMessage;

// $authorizedTargetId has already passed the consumer's caller/target authorization.
$profile = $handler->handle(QueryMessage::create(new GetAgentProfile($authorizedTargetId)));
```

Each invocation calls `AgentRepository::getById()` anew. Consumers must implement an authoritative current lookup,
not a principal-name cache. An ACTIVE target produces immutable `Agent\Query\AgentProfileView`, whose getters are
`getAgentId()` and `getName()` and whose `toArray()` contains exactly:

```json
{"agent_id":"018f0000-0000-7000-8000-000000000070","name":"Deployment worker"}
```

The result retains neither the aggregate nor an administrative `AgentView`. No assigned Permission is resolved;
credentials, lifecycle state, revisions and timestamps are absent. A later read observes a committed rename; a
previous result stays unchanged. A read is only an observation, not a lock, authority snapshot or permission to act.

Both missing and revoked targets return **null**, meaning generically unavailable; consumers must not distinguish
these cases publicly. Repository failures propagate, rather than becoming absence or a stale successful result.
The consumer response/log boundary must sanitize operational errors and traces. This query writes nothing, opens no
transaction, commits nothing and emits no events, including failure events. It does not acquire writer admission.

### Authorization and the Tool boundary

`GetAgentProfile` accepts an explicit internal target `AgentId` for reuse; that ID grants no authority. Protect every
exposed query entry point, including direct bus dispatch. The query does not enforce `AGENT_PROFILE_READ` or select
a principal. `ADMIN_SAFE` describes delegation eligibility, not automatic caller authorization.

[TASK-00071's profile Tools](agent-profile-tools.md) bind the read target **solely** to the current authenticated Agent
principal's ID, accept no caller-supplied target, and enforce the separate read/update requirements through
[TASK-00035's MCP authorization](agent-mcp-authorization.md) before protected work. The read Tool uses only this
minimal result and Common's matching derived JSON text. Tool declarations, error mapping and principal-only selection
belong to that composition, not this query handler. No administrator endpoint or authorization bypass is implied.

## Stable consumer-owned Permission identities

Domain `Agent\AgentProfilePermissions` exposes `READ = 'AGENT_PROFILE_READ'`, `UPDATE = 'AGENT_PROFILE_UPDATE'`, and
`definitions(PermissionId $readId, PermissionId $updateId)`. The factory returns exactly two existing
`ManagedPermissionDefinition` values in read/update order, both `ADMIN_SAFE`. Equal ID values reject with
`ManagedPolicyDefinitionException`. It allocates no IDs, persists nothing and assigns no Permission or Role.

Each consumer generates its **two distinct IDs once** with Fight Common `Uuid::comb()` during seed/migration
authoring, and fixes both returned strings in its version-controlled seed/migration. For example, run only during
that one-time authoring step:

```php
use Fight\Common\Domain\Value\Identifier\Uuid;

$readIdToPersist = Uuid::comb()->toString();
$updateIdToPersist = Uuid::comb()->toString();
```

Persist the pair with their respective names. Do not run this generation on application startup, reconciliation,
read, deployment retry or each seed execution. A seed execution consumes the already-fixed values. The package has
no fixed UUIDs; different consumers own independent stable identities. Routine policy composition then uses those
fixed strings, not newly generated IDs:

```php
use Fight\AccessControl\Domain\AccessControl\Agent\AgentProfilePermissions;
use Fight\AccessControl\Domain\AccessControl\ManagedPolicy\ManagedPolicy;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionId;

$readId = PermissionId::fromString($fixedProfileReadId);
$updateId = PermissionId::fromString($fixedProfileUpdateId);
$profileDefinitions = AgentProfilePermissions::definitions($readId, $updateId);

$policy = new ManagedPolicy(
    [...$otherManagedPermissions, ...$profileDefinitions],
    $managedRoles,
    [...$otherCodePermissionReferences, $readId, $updateId]
);
```

Merge these definitions and code references into the consumer's **complete** policy, without duplicate identities,
names or references. Existing `PreviewManagedPolicy` / `ReconcileManagedPolicy` accept that policy. Reconciliation
is exact: do not replace an existing policy with a two-Permission fragment or omit existing managed Roles. Keep
these IDs in `referencedPermissionIds` while consumer code depends on them. The factory adds no memberships to
`$managedRoles` and does not grant Agents either Permission. Any explicit grant is a separate consumer-authorized
operation using the existing assignment and tier fences; neither reading nor defining a Permission grants it.

## Evidence and limits

- `tests/Domain/AccessControl/Agent/Query/AgentProfileTest.php`: required target round trip/rejection, typed immutable
  minimal result and exact field allowlist.
- `tests/Application/AccessControl/Agent/QueryHandler/GetAgentProfileHandlerTest.php`: fresh successive names,
  equal missing/revoked unavailability after success, storage failure rather than cached output, no writes and no
  Permission/transaction/event collaborators. Repository responses model committed state; they are not a database run.
- `tests/Domain/AccessControl/Agent/AgentProfilePermissionsTest.php`: exact names/tiers, stable caller-supplied IDs,
  independent consumers, equal-ID rejection and existing managed-policy composition without Role grants.

The TASK owns focused/full local gate receipts. These package fixtures do not qualify consumer persistence,
managed-policy seeding, entry-point authorization, MCP scanning/transport/runtime, release or deployment.
