# Recoverable Agent rotation

[TASK-00048](../planning/tasks/archive/00048-TASK.md) replaces raw-return rotation with an authorized, retained-key
operation. It consumes [provisioning correlation](agent-provisioning-operations.md),
[atomic predecessor retirement](agent-credential-retirement.md) and the existing
[protected delivery attempt](agent-credential-delivery.md). Compose [restart recovery](agent-delivery-recovery.md),
[maintenance](agent-delivery-maintenance.md), [cohort guards](agent-operation-cohorts.md) and
[restoration guards](agent-restoration-safety.md) together. Run the [consumer checks](agent-integration.md#restoration-and-consumer-evidence-handoff)
on real adapters; the package tests do not qualify a deployment.

## Public API and composition

TASK-00182 replaces the direct service/result API with immutable `RotateAgentCredential(key, request)` and
registered void `RotateAgentCredentialHandler` as an **unreleased breaking caller-contract change**. Persist the key
and original request **before** dispatch. The request contains the target Agent ID, original expected credential ID and revision,
and a registered destination identity/binding revision. The scope comes from trusted authenticated context. No raw
secret, URL, path, caller-computed digest or implicit operation identity is accepted.

```php
use Fight\AccessControl\Domain\AccessControl\Agent\Command\RotateAgentCredential;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\AgentRotationRequest;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\AgentOperationView;
use Fight\AccessControl\Domain\AccessControl\AgentCredentialOperation\Query\GetAgentOperation;

$key = new AgentOperationKey($trustedScope, AgentOperationId::generate());
$request = new AgentRotationRequest($agentId, $currentCredentialId, $currentRevision, $registeredDestination);
// Persist both key and ORIGINAL request before dispatch, independently of the envelope ID.
$commandBus->execute(new RotateAgentCredential($key, $request)); // void, now or later
/** @var AgentOperationView $view */
$view = $queryBus->fetch(new GetAgentOperation($key, $registeredDestination));
// Status before execution may be indeterminate. It cannot identify queue progress or rejection.
// On response loss or execution uncertainty, retain this key AND original predecessor request.
// Do not replace it with the now-current credential, create another key, or infer rollback.
```

Register `RotateAgentCredentialHandler::commandRegistration()` through the configured supported Common CommandBus;
register the existing `GetAgentOperationHandler` through its QueryBus. No service facade or result channel remains.
See [issuance dispatch composition](agent-integration.md#public-composition-map) for message routing and envelope
handling. QueryBus methods still return mixed.
The Command round-trips original scoped key, Agent, expected credential ID/revision and destination ID/revision as
strict secret-free fields. It preserves the canonical marker/bytes and original predecessor across envelope changes.
Confirmed Query issuance identifies the original Agent/credential/revision, delivery ID, destination and write order,
not a later successor or delivery/activation/use authority. Status contains no publication warning or secret handle.

Invocation may be synchronous or asynchronous, delayed or redelivered without an active submitting transport.
Consumers own broker/runtime/error handling and independently authenticate the real executing invoker/delegation;
serialized actor/scope, metadata and enqueue-time authorization never confer execution authority. An async submitter
is not promised the worker's exception.

Inject the same capabilities as provisioning: `AgentRepository`, `AgentCredentialOperationRepository`,
`AgentOperationAuthorization`, `HmacSharedSecretGenerator`, `HmacSharedSecretCipher`,
`AgentDeliveryCipher`, `Clock`, `TransactionalUnitOfWork`, `EventDispatcher`, optional `AgentOperationLimits`, then
optional last nullable `AuditEvidenceRepository`. Omitted/null disables durable audit, not authorization or retirement.
See [optional atomic issuance audit](agent-provisioning-operations.md#optional-atomic-issuance-audit-unreleased) for
required current origin hydration, evidence mapping, atomic failure and later opt-in semantics shared by both handlers.
The new mandatory `AgentOperationAuthorization::authorizeRotation(scope, destination, target)` authenticates the real
invoker and fences current scope, explicit delegation, target authority and destination ownership **before key lookup**.
It returns explicitly established nullable originating provenance independently of the real invoker. Every retry, including collision resolution and retired original
issuance, checks current authority again. Authorizing a retry must not require its original predecessor still current.
No actor string, cached allow, outer transaction or nonparticipating external policy store substitutes for this port.

The old `AgentCredentialLifecycleService::rotate()` and aggregate `rotateCredential()` are removed, not callable
rejection stubs. `AgentCredentialLifecycleService` accepts Agent repository, Clock, Unit of Work, event dispatcher,
then optional last nullable audit repository. See [optional revocation audit](agent-credential-retirement.md#optional-atomic-revocation-audit-unreleased).
Its retirement and publication-failure rethrow behavior is unchanged. The
[current Agent contract](agent-current-contract.md) has no legacy/adoption mode or recovery marker.

## Request binding and transaction

The single supported canonical contract (marker `2`) uses the ordered JSON tuple `rotate`, target UUID, expected
credential UUID, expected revision, destination UUID, destination binding revision. Expected revision is an integer
from 0 through `PHP_INT_MAX - 1`; destination revisions remain positive. UUID value objects normalize equivalent
representations. Existing keys validate their marker before comparison; unsupported markers reject and permanent
tombstones never become new work. Changed kind, target, predecessor identity/revision or destination binding conflicts
without issuance. No outcome-affecting per-request options exist. The [canonical request and name contract](agent-integration.md#canonical-request-and-name)
has no historical reader or selectable creation version; `canonicalize()` takes no argument.

Inside one package-owned transaction:

1. Authorize current scope/target/destination. Only absent-key enabled work requires originating audit identity,
   admitted before its first mutation; existing resolution and disabled audit need no audit-only origin.
2. Read the retained key, including tombstones. If present, resolve the original request; do not load/check a current
   predecessor, generate material, reserve another slot order, mutate authority, audit or publish another success fact.
3. For an unseen key, load the target. `Agent::assertRecoverableRotation()` checks the exact active expected
   credential ID/revision before generation. Every predecessor requires current-operation correlation.
4. Reserve the next global destination write version. Generate a credential and separately encrypt authentication
   and integrity-bound delivery material. `Agent::rotateRecoverableCredential()` preserves identity, name, creation
   time and Permission assignment while advancing only credential revision. Equal credential IDs/backdated successors
   reject. One active credential and terminal revocation remain invariant.
5. `AgentRepository::replace(expected, successor)` uses the existing same-connection cancellation seam. It retires the
   predecessor's exact original operation, removes its material, advances its state revision and records supersession.
   Original correlation and delivered/terminal history survive. Missing correlation rejects without fabricating it.
   No key, decrypt or sink access is needed to cancel.
6. Add the new pending operation/delivery and enabled safe rotation audit; commit all state once. Capacity, cancellation,
   encryption, audit, authorization or persistence failure rolls back every write, including slot reservation and
   predecessor retirement. All adapters and authority writers must hold the shared fences until completion.

A scoped-key collision or lost predecessor CAS rolls back the entire loser, then attempts **one resolve-only**
transaction under fresh authority. A same-key winner returns its original issuance; conflicting requests reject.
A missing unique-key winner is retryable `CONTENTION`; a missing predecessor-race winner is `CONFLICT`. There is no
second generation within this invocation and no silent retry of the mutation. Different keys against one predecessor
cannot commit two successors. An adapter must distinguish typed scoped-key collisions from arbitrary storage faults,
never retry callbacks invisibly, and never acknowledge a commit before it is confirmed. A closed Unit of Work rejects;
recompose and retain the original request rather than inventing another key.

## Failure, bounds and delivery

Callback failures use the [issuance Application categories](agent-provisioning-operations.md#public-boundary),
with no chained provider error. Only an actual scoped-key insertion collision or the known predecessor/CAS refusal
enters resolution-only retry; unrelated provider or successor-construction faults stay internal. Wrong repository
Agent/key/correlation and invalid reservation promises raise sanitized `AgentIssuanceFailed`, not caller rejection.
The local callback still returns known `AgentIssuance` under a matching `@var`; Common's native UoW remains mixed.
If the callback finished but commit did not confirm, throw sanitized `AgentOperationUncertainException` without claiming
issuance or rollback—even if persistence actually committed. A fresh original-key retry reconciles either outcome;
an authorized Query can confirm stored original issuance, while absence remains indeterminate.

Only a confirmed new rotation publishes `AgentCredentialRotated`, identified by its Agent/credential/revision tuple.
Publisher failure completes void handling after best-effort fixed, sanitized `AgentCredentialLifecycleFailed`
evidence; failure of both publishers cannot disguise the committed outcome. No invocation warning is returned,
stored or reconstructed by the Query. There is no outbox or
automatic event retry guarantee. Same-key retries emit no duplicate mutation/audit/fact. Durable operation state,
not notifications, owns recovery. Qualify scheduler-only restart with both publishers failing.

Default pending limits are **100 per originating scope and 10000 globally**, with validated overrides of 1–10000 and
1–1000000 respectively (global at least per-scope). `AgentOperationLimits` retains provisioning's name bound, which
is irrelevant to rotation's fixed-shape request. No manual limits or extra human approval are required. Admission
counts pending copies after atomic predecessor cancellation; an unrelated full queue can still reject new rotation,
rolling that cancellation back. Existing-key resolution bypasses capacity. Retirement and tombstones preserve keys
and slot ordering forever; capacity never evicts correlation. These are the same limits, not a separate rotation quota.

Delivery uses the new operation's original immutable tuple and bytes. Rotation before admission prevents predecessor
materialization. A previously confirmed admission may already be in flight and stage inert old bytes at its fixed sink;
rotation does not promise cross-system recall. Fresh completion checks reject the obsolete credential/revision and
slot order. If successor delivery arrives first, the sink's high-water rule rejects late predecessor staging. Receipts
and issuance never authorize activation/use. All concrete repository Agent parameters and transaction callbacks retain
the [sensitive-trace obligations](agent-credential-retirement.md#composition-and-atomic-cancellation).

## Evidence and limits

Run the [Agent consumer checks](agent-integration.md#restoration-and-consumer-evidence-handoff) on actual adapters.
Qualify rotation and old-authority denial across nonce races, concurrent writers, publication failure and restart;
controlled package interleavings do not qualify database concurrency, crypto, sink or activation/use.
