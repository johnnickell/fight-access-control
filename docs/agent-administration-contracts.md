# Agent administration contracts (unreleased)

The four void handlers are `UpdateAgentHandler`, `GrantPermissionToAgentHandler`,
`RevokePermissionFromAgentHandler` and `ReplaceAgentPermissionsHandler`. Direct Permission coordination remains
internal at `Application/AccessControl/Agent/PermissionAssignment/AgentPermissionAssignmentCoordinator`.
The three direct Permission handlers support the optional atomic audit composition below. This adds no
administration API, grant or endpoint; name-update audit remains separately owned.

## Authorization and successful execution

Consumers authorize every entry point and exact target before dispatch, including workers and direct calls.
An actor/Agent/Permission ID, authenticated profile, expected revision or ADMIN_SAFE definition grants no authority.
ADMIN_SAFE determines membership eligibility, not who may administer an Agent. Existing protected memberships may
still be revoked; grant and complete replacement require eligible definitions even for apparent no-ops.

Register each handler's `commandRegistration()` with the supported Common router/bus. Successful execution remains
void, whether a real transition or a validated no-op. No-op writes nothing, advances no revision/timestamp and emits
no success fact. Complete Permission sets retain first-occurrence normalization and order-independent equality;
explicit expected assignment revisions are checked before accepting equal-state no-ops. Rename remains last-committed
name intent, with no caller name revision. See [name updates](agent-name-updates.md) for the input-derived synchronous
acknowledgement, lazy clock and exact current-operation correlation.

The coordinator checks exact target and single-Permission identities before mutation or no-op. Complete replacement
first validates explicit Permission lookups, then reuses `ExactPermissionResolver` for exact bulk definitions and
ordering; it does not implement another resolver. Missing explicit definitions are intentional absence. A bulk
snapshot that cannot satisfy the complete request after those lookups fails closed as an internal consistency fault,
not an inferred missing resource or successful empty set. These reads are not a stronger cross-read atomic snapshot
promise: concurrent changes may require a fresh authorized attempt. Current definition/reference/tier and full-Agent
predecessor fences remain authoritative through the transaction. All writers, including consumer writers, must
participate in the shared cohort/reference/credential fences.

## Intentional failures versus internal faults

Application translates only known Domain outcomes at their actual owner; unknown faults are not classified by
message or integer code. Domain types remain inward and independent of Common Application exceptions.

| Condition | Application outcome |
| --- | --- |
| Absent Agent or explicit Permission definition | Common `ResourceNotFound` |
| Missing/inactive rename target, deliberately indistinguishable | Common `ResourceNotFound` |
| Invalid command name | Common `InvalidInput`, retaining the Domain cause |
| Protected grant/complete set or exhausted assignment revision | Common `RuleViolation` |
| Explicit expected assignment revision does not match | Common `PreconditionFailed` |
| Assignment/reference/tier compare failure or backdated rename | Common `StateConflict` |
| Wrong returned identity, inconsistent bulk definitions, unknown storage/programmer/clock fault | Internal failure; never fabricated 404/409/422 |

`Agent::replacePermissions()` now identifies revision mismatch with Domain
`AgentPermissionRevisionMismatchException`. Rename uses Domain `AgentUnavailableException` for missing/inactive
repository targets and `AgentNameUpdateConflictException` for backdated changes. Both extend `AgentUpdateException`;
repositories must implement these precise outcomes instead of throwing the generic base for ordinary refusal.
The generic base and unknown repository exceptions are not blanket-translated. Repository rename owns fenced
identity/correlation validation; a handler-side advisory read must not replace it.

**Intentional pre-v1 change:** update consumer catches and repository adapters to these current contracts directly.
There is no compatibility facade, old exception mode or historical reader. Domain callers still receive Domain
exceptions; configured Application calls receive the deliberate Common categories. Exact-class MCP profile failure
bindings cover the new Application outcomes separately from ordinary HTTP; no HTTP envelope is adopted by MCP.

## Transaction and publication truth

Each public handler owns one actual Unit of Work. The assignment coordinator stages mutations and returns separate
typed relationship/revision facts and the trusted transition time alongside the existing Event. It owns no transaction,
audit insertion or publication; handlers never inspect an Event to reconstruct evidence. Success facts follow confirmed
commit only. Known owned callback results use matching local nullable-Event `@var` annotations with Common's native
mixed UoW result; no generic UoW, result DTO or redundant runtime result check is required.

A pre-commit failure rolls back staged changes. A thrown commit acknowledgement is not proof of rollback: confirm
current authoritative state under current authorization before deciding how to retry. Success-publication failure
still throws with committed state intact. Operation-constant `CommandFailedEvent` evidence retains the original safe
Command; secondary notification failure cannot replace the primary throwable. Neither name nor Permission
administration inherits Agent issuance's special publication policy. There is no reliable event replay/outbox claim.
Safe notification text does not sanitize the rethrown exception; consumers own response/log diagnostics and redaction.

## Optional direct Permission audit composition (unreleased)

GrantPermissionToAgentHandler, RevokePermissionFromAgentHandler and ReplaceAgentPermissionsHandler accept an optional
last nullable `AuditEvidenceRepository`. Omitted/null intentionally disables durable audit; configured failure never
downgrades to unaudited success. Existing required UserId actors and Command/Event round trips remain unchanged.
Inject the ordinary shared Unit of Work and repositories on the same actual transaction connection:

```php
$handler = new GrantPermissionToAgentHandler(
    $agentRepository,
    $permissionRepository,
    $clock,
    $unitOfWork,
    $eventDispatcher,
    auditEvidenceRepository: $auditEvidenceRepository,
);
```

The handler persists evidence after the authoritative assignment replacement and before commit. Actions are
`agent.permission_granted`, `agent.permission_revoked` and `agent.permissions_replaced`. Evidence retains typed
User actor, Agent subject, unique evidence identity, actual message correlation, the exact transition time and
one authoritative before/after assignment-revision advance. Typed granted/revoked Permission lists describe only
changed relationships. Complete and empty replacement each produce one record, not one per Permission or incidental
write; unchanged memberships are omitted. No Agent snapshot, credential envelope, free text or diagnostics enter
these facts. Evidence is not automatically authorized public/log data.

Validated no-ops create no mutation, revision advance, success fact or evidence. Stale or ineligible equal-set intent
still rejects. Audit failure or final concurrency loss rolls back state and evidence together; uncertain commit is
not guessed rollback. Ordinary publication failure still throws with both committed. Consumers must support the new
action/fact mappings and qualify actual shared connections, transaction-duration cohort/definition/reference and
complete-state CAS fences for every writer before adoption. No compatibility reader, migration/backfill, consumer
schema change or automatic workaround removal is supplied.

## Qualification and limits

`AgentAdministrationContractTest`, the existing assignment/name/cohort/reference/failure suites and
`tests/Adapter/Command/AgentAdministrationCommandTest.php` exercise exact identities, intentional categories,
Domain/refusal versus repository faults, validated no-ops and persisted transaction/publication outcomes.
The adapter suite uses real supported Common registration, serialized dispatch and safe PSR-15 error composition.
The current declared Common floor is `^1.5.1`; Engineer evidence records the exact tagged minimum and ordinary
resolution. Generic Command/Event inference remains separately owned and is not established by these runtime tests.
[Static contract fixtures](type-contracts.md) prove local void/nullable-Event/list/callback contracts separately.
`AgentPermissionAuditTest` additionally covers enabled/disabled evidence, normalized/empty replacement, no-change,
rollback, final CAS loss, both uncertain-commit possibilities and ordinary double-publication failure with shared
transaction-aware storage that rejects nested transactions.

Package tests use controlled transaction-aware repositories, not production persistence. Consumers separately qualify
actual shared connections, every writer, locks/CAS/races, restoration/correlation, authorization, transport bootstrap,
error reporting and restart/uncertain-commit behavior. No Common or consumer implementation, release or deployment
is included. Independent Review and nonvisual behavioral QA remain required for this Engineer candidate.
