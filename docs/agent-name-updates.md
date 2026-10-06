# Agent name updates (unreleased)

`UpdateAgent` and `UpdateAgentHandler` provide one generic name-only command for consumer-authorized User or Agent
entry points. This is TASK-00069's package capability, not the MCP profile tools, an administrator endpoint, a
production persistence adapter or a release. See the [current Agent contract](agent-current-contract.md) and
[integration guide](agent-integration.md) for the surrounding authority and operation model.

## Intent, provenance and acknowledgement

The immutable command constructor is `UpdateAgent(AgentUpdateInitiator, AgentId, string)`. `AgentUpdateInitiator`
accepts a typed `UserId` or `AgentId`; its type is derived from that identifier, never a separately contradictory
flag. Its canonical representation is `{type: "user"|"agent", id: UUID}`. The command's required canonical keys
are `initiator`, `agent_id`, and `name`. `fromArray()` rejects missing required data and unknown initiator types.
The input name remains raw in the command; the handler normalizes it with `AgentName::fromString()` (PHP `trim`,
nonempty, at most 120 characters after trimming). Names are neither unique nor revision-checked.

**Provenance is not authorization.** Protect every dispatch entry point: HTTP, CLI, workers, direct bus calls and
any direct repository access. Derive the initiator from trusted authenticated context, then apply consumer caller,
target and scope policy. Supplying a User or Agent ID does not prove authentication or grant any authority. Future
self-service tools must derive both target and initiating Agent solely from the authenticated principal; those
Tool bindings and their distinct read/update Permissions remain TASK-00070/00071/00035 work.

Register `UpdateAgentHandler::commandRegistration()` with a **synchronous** Common CommandBus. Inject the Agent
repository, Application Timing `Clock`, Common `TransactionalUnitOfWork` and `EventDispatcher`. The handler and bus
return void. After successful dispatch, a consumer may acknowledge the target and validated command input:

```php
$name = AgentName::fromString($command->getName());
$bus->execute($command); // SynchronousCommandBus: returns only after the handler completes
$acknowledgement = ['agent_id' => $command->getAgentId()->toString(), 'name' => $name->toString()];
```

Real changes and normalized no-ops use exactly the same `{agent_id, name}` value. No `changed` field, separate
changed/no-op sentence, result-bearing handler or compensating read is required. The acknowledgement describes the
successful request, **not a fresh read of the latest name** after competing commits. Any thrown failure, including
post-commit publication failure, prevents success acknowledgement. Future MCP binding uses Common's derived JSON
text for that same structured value; this TASK supplies no Tool or wire error representation.

## Name-only persistence and concurrency

`Agent::rename(AgentName, DateTimeImmutable)` returns an immutable successor changing only name and `updatedAt`,
or the original object for an unchanged name. It checks ACTIVE state **before** the no-op branch and rejects a
backdated real update. It never changes ID, creation time, lifecycle, credential ID/revision/envelope or Permission
IDs/assignment revision. Constructing a successor alone neither persists nor authorizes anything.

Every consumer Agent repository now implements:

```php
public function rename(AgentId $id, AgentName $name, DateTimeImmutable $renamedAt): bool;
```

This is a mandatory name-intent capability, not a generic whole-Agent update. In the enclosing package transaction:

1. Validate current cohort admission, shared-connection participation and trusted restoration readiness, including
   direct calls and no-ops. Hold the cohort fence through commit/rollback.
2. Hold the current Agent/credential fence shared with lifecycle and Permission writers. Validate exactly one current
   persisted operation correlation; missing, ambiguous or inconsistent correlation fails closed. Do not load keys,
   invoke a sink, create issuance, retire a credential or apply new-operation capacity limits.
3. Load authoritative current Agent state **under that fence** and apply its `rename()` transition. Missing/inactive
   targets throw `AgentUpdateException`; missing correlation or unsupported participation throws sanitized
   `AgentOperationRejectedException`. False means only an unchanged normalized name on a still ACTIVE, admitted
   target, never absent/revoked/conflicting/unavailable work. Storage faults throw.
4. Persist only the real changed name and update time; return true for that change. Preserve credentials,
   Permissions, revisions, creation time and all operation/delivery state. No-op performs no write or timestamp
   change. Rollback restores the original name. No nested/independent commit or publication belongs in a repository.

No expected old name or name revision is accepted: the last committed name wins. The method cannot accept a stale
aggregate or unsafe credential/Permission successor at all. A previously read aggregate does not control the write;
current state must win. Revocation before the write rejects even identical names. Permission changes and rotations
before it are retained without replacement, issuance or cancellation by the rename. Other whole-Agent write paths
must compare the complete current predecessor, so a stale lifecycle/Permission successor cannot undo a committed
rename. Consumers retry conflicting authorized intents from current state rather than silently upserting snapshots.
Use trusted, non-backdating clocks; a real request with time before the current update rejects instead of regressing
metadata. Concurrent requests with the same timestamp may both rename; timestamps are not a name revision.

## Commit and publication outcomes

One handler invocation owns one transaction for valid input. A real persisted change yields secret-free
`AgentNameChanged(initiator, agentId, normalizedName, changedAt)` only after confirmed commit. The event serializes
required `initiator`, `agent_id`, `name`, and fractional `changed_at`, with the exact initiating type and target.
No-op commits validation without writes or a success fact. Invalid names reject before entering the transaction.

Pre-commit persistence or commit failure rolls back. Ordinary `CommandFailedEvent` retains the original safe command
and error message; the original throwable rethrows even if failure notification also throws. Consumers sanitize
storage errors at their response/log boundary. Post-commit event failure reports failure but leaves the rename
committed; do not claim rollback, event delivery or a success acknowledgement. Retrying rereads current state: if
already unchanged it produces no duplicate name fact. There is no outbox/reliable event replay. The scoped issuance
warning exception for provision/rotation does **not** apply to rename. Uncertain commit is not proof of rollback;
reconcile from current authoritative state under current authorization, without claiming which concurrent request
won from a missing event.

## Evidence and consumer qualification

- `AgentRenameTest`: name-only immutable transition, inactive/no-op/backdated behavior, typed identity equality,
  User/Agent message round trips, fractional event time and missing/unknown provenance rejection.
- `UpdateAgentHandlerTest`: void handler orchestration and post-commit publication, real/no-op effects, invalid and
  missing/revoked targets, write/commit rollback and original throwable preservation when either publisher fails.
  Controlled pre-write Fiber schedules prove a competing rename, Permission assignment or real rotation wins before
  the pending rename; revocation defeats real and no-op attempts. Stale whole-Agent writes reject after rename.
  Direct modeled writes deny missing/ambiguous correlation, missing transaction and restoration admission.
- `AgentNameComponentsTest`: generated additive `Fight.AccessControl.UpdateAgent` and `AgentUpdateInitiator` schemas
  match actual serialized values and the owning principal-type enum, without a schema-only raw-name length limit.

These use transaction-aware in-memory adapters and real public package handlers/services, not a production database.
Consumers must implement and qualify actual shared-connection locks, all writer races, rollback, current-correlation
hydration, restoration admission, caller-policy wiring and response sanitization before adoption. No data migration,
legacy alias, consumer runtime qualification, package signing/publication or deployment is included. The new repository
method requires adapter updates and belongs in the next pre-v1 minor release, not a compatible patch/backport.
