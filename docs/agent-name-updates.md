# Agent name updates (unreleased)

`UpdateAgent` and `UpdateAgentHandler` provide one generic name-only command for consumer-authorized User or Agent
entry points. This is TASK-00069's package capability, reused by the separate
[self-service MCP profile Tools](agent-profile-tools.md), not an administrator endpoint, production persistence adapter
or release. See the [current Agent contract](agent-current-contract.md) and
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
target and scope policy. Supplying a User or Agent ID does not prove authentication or grant any authority.
[Self-service profile Tools](agent-profile-tools.md) derive both target and initiating Agent solely from the
authenticated principal, with distinct read/update Permissions through the reusable MCP availability boundary.

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
post-commit publication failure, prevents success acknowledgement. The separate MCP profile binding uses Common's
derived JSON text for that same structured value; its guide owns the Tool and safe wire error composition.

## Name-only persistence and concurrency

`Agent::rename(AgentName, DateTimeImmutable)` returns an immutable successor changing only name and `updatedAt`,
or the original object for an unchanged name. It checks ACTIVE state **before** the no-op branch and rejects a
backdated real update. It never changes ID, creation time, lifecycle, credential ID/revision/envelope or Permission
IDs/assignment revision. Constructing a successor alone neither persists nor authorizes anything.

Every consumer Agent repository now implements:

```php
/** @phpstan-param Closure(): DateTimeImmutable $now */
public function rename(AgentId $id, AgentName $name, Closure $now): ?DateTimeImmutable;
```

This is a mandatory name-intent capability, not a generic whole-Agent update. In the enclosing package transaction:

1. Validate current cohort admission, shared-connection participation and trusted restoration readiness, including
   direct calls and no-ops. Hold the cohort fence through commit/rollback.
2. Hold the current Agent/credential fence shared with lifecycle and Permission writers. Validate exactly one current
   persisted operation correlation; missing, ambiguous or inconsistent correlation fails closed. Do not load keys,
   invoke a sink, create issuance, retire a credential or apply new-operation capacity limits.
3. Load authoritative current Agent state **under that fence**. Missing/inactive targets throw `AgentUpdateException`;
   missing correlation or unsupported participation throws sanitized `AgentOperationRejectedException`. After current
   state/correlation validation, invoke `$now()` exactly once and apply `Agent::rename($name, $now())` with that sample.
   The callback supplies only trusted current time; the handler passes `$clock->now(...)`, not a captured timestamp.
   Never sample before waiting for writer admission, retain the callback or invoke it outside this transaction.
4. Persist only the real changed name and update time; return that exact `DateTimeImmutable` for the handler's
   post-commit fact, not a separately sampled time. Preserve credentials, Permissions, revisions, creation time and
   all operation/delivery state. Return `null` only for an unchanged normalized name on a still ACTIVE, admitted
   target, never absent/revoked/conflicting/unavailable work. No-op performs no write or timestamp change. Storage or
   clock faults throw; rollback restores the original name. No nested/independent commit or publication belongs in
   a repository. This persistence result does not change the void handler/bus or input-derived acknowledgement.

No expected old name or name revision is accepted: the last committed name wins. The method cannot accept a stale
aggregate or unsafe credential/Permission successor at all. A previously read aggregate does not control the write;
current state must win. Revocation before the write rejects even identical names. Permission changes and rotations
before it are retained without replacement, issuance or cancellation by the rename. Other whole-Agent write paths
must compare the complete current predecessor, so a stale lifecycle/Permission successor cannot undo a committed
rename. Consumers retry conflicting authorized intents from current state rather than silently upserting snapshots.
Use trusted, non-backdating clocks, **sampled after acquiring the writer fence**. If another rename, Permission write
or rotation commits while this request waits, the later admitted request samples fresh time and renames current
state without losing that authority. Sampling before the fence would turn ordinary advancing-clock contention into
a false stale-time failure. A genuinely backdating clock still rejects a real update instead of regressing metadata;
no clamping, synthetic time or caller name-revision token is introduced. Concurrent requests with the same timestamp
may both rename; timestamps are not a name revision.

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
  Controlled pre-write Fiber schedules advance one shared clock while a competing rename, Permission assignment or
  real rotation commits before the pending rename. The resumed rename wins with fresh time, preserves current authority
  and publishes the exact persisted fractional timestamp. Revocation defeats real and no-op attempts. Stale whole-Agent
  writes reject after rename. Later no-ops leave the timestamp intact; direct writes reject a genuinely backdating clock.
  Direct modeled writes deny missing/ambiguous correlation, missing transaction and restoration admission before sampling.
- `AgentNameComponentsTest`: generated additive `Fight.AccessControl.UpdateAgent` and `AgentUpdateInitiator` schemas
  match actual serialized values and the owning principal-type enum, without a schema-only raw-name length limit.

These use transaction-aware in-memory adapters and real public package handlers/services, not a production database.
Consumers must implement and qualify actual shared-connection locks, all writer races, rollback, current-correlation
hydration, restoration admission, caller-policy wiring and response sanitization before adoption. No data migration,
legacy alias, consumer runtime qualification, package signing/publication or deployment is included. The new repository
method requires adapter updates and belongs in the next pre-v1 minor release, not a compatible patch/backport.
