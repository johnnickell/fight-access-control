# Current Agent continuation admission

Unreleased [TASK-00088](../planning/tasks/00088-TASK.md) supplies explicit current-authority admission for repeated
protected work. It does not change the ordinary request snapshot, supply a Common Subscription/Task integration,
issue a bearer/session credential, run a worker, grant scope or qualify a production lock implementation.

## Trusted original binding

Types below live in `Fight\AccessControl\Application\AccessControl\Agent`, under `Security`, `Service` or `Exception`.

| Contract | Purpose |
| --- | --- |
| `Security\AgentContinuationBinding::fromRequest($provider, $request, $correlationId)` | Uses the same trusted request-scoped `CurrentAgentPrincipalProvider` as transport admission. Resolves successful authentication if necessary; an already admitted provider reuses its original snapshot and consumed nonce. |
| `CurrentAgentPrincipalProvider::continuationContext()` | Returns original Agent ID, credential ID/revision and authentication time only after complete successful resolution. These public claims are **not** binding proof. |
| `Security\AgentContinuationContext` | Immutable original claims, not Permissions, relationship grants or a bearer credential. Its public constructor is useful to a trusted handoff adapter, not a binding factory. |
| `Security\AgentContinuationBinding::fromHandoff($verifier)` | Creates a worker-local binding only through the explicitly injected trusted `Service\AgentContinuationHandoff::verify()` capability. Verification failure throws the neutral rejection. |
| `Security\AgentContinuationGuard::run($binding, $requirements, $scope, $action)` | Revalidates and invokes one synchronous bounded action under the required composite fence. Returns no Boolean, admission token or continuing lease. |

Example of the request-local composition (consumer-supplied collaborators, not an endpoint):

```php
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentContinuationBinding;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentContinuationGuard;
use Fight\AccessControl\Domain\AccessControl\Permission\PermissionName;

$binding = AgentContinuationBinding::fromRequest($provider, $signedRequest, $safeCorrelationId);
$guard = new AgentContinuationGuard($agents, $permissions, $clock, $qualifiedFence);
$guard->run($binding, [PermissionName::fromString('VIEW_AGENTS')], $currentTargetScope, $boundedAction);
```

Requirements, scope, fence and action come from **trusted consumer configuration** for the same exact operation.
Do not let callers supply a verifier, select an include-all scope, replace requirements or choose a different action
behind an admitted target. IDs, OAuth scope, human Roles, protocol metadata, a heartbeat, saved principals, copied
Permission lists and operation/Task handles cannot construct a binding. The binding has a private constructor,
rejects serialization and unserialization, and has an empty debug/JSON projection. Cloning a local verified binding
adds neither validity nor authority. Hostile in-process PHP/reflection is not a sandboxed trust boundary.

### Validity and lifetimes

The provenance window is fixed: **original authentication sample ≤ admission sample < original sample + five
minutes**. The original sample is the provider's existing trusted `Clock::now()` sample at the start of the successful
authentication flow, not the signed timestamp. Slow authentication/fence waits consume this window. Cached resolution,
binding recreation, queue handoff, reconnects and worker restarts never refresh it. Negative revisions, future context
and exact/post-expiry admissions deny. Consumers qualify their clock and clock-regression behavior.

This is a finite provenance limit, **not five minutes of permission to work**. Every bounded action still needs fresh
current checks. Long jobs or delayed queues may outlive it: stop protected admission and use the consumer's actual
fresh authentication/authorization path to establish new provenance. Do not replay a consumed signed request, update
the original time, switch credentials, invent a refresh token or periodically renew unconditional permission.

The ordinary provider stays request-scoped. Retain only the binding for repeated work; it retains no signed request,
nonce, secret, provider, initial Permission snapshot or initial scope decision. The guard may be reused only when its
repositories/clock/fence are safe for that lifetime; it caches no principal or allow across invocations. Scope is bound
to the particular protected action, not an accidentally retained request container.

### Worker handoff

The binding itself has **no wire format**. A trusted producer may obtain `getContext()` after admitted authentication
and retain its exact tuple/time with the consumer's protected work record and authentication-provenance evidence.
A separately composed worker verifier must verify that evidence, producer, record integrity, intended work/recipient,
current handoff validity/revocation policy and original tuple/time before returning a reconstructed context. Missing,
modified, caller-originated or unverifiable records throw; no verifier means no supported handoff. A signature or ID
on an untrusted principal is not, by itself, such verification. This package supplies no queue, storage, signing-key,
token or session issuer. Do not expose `fromHandoff()` as an API accepting caller-provided implementations.

The resulting binding proves original authentication context, not authority over the saved work. Worker scope must
independently check the current work/recipient/relationship and any ongoing handoff revocation at each action under
the same fence. Expiry and current Agent/credential/Permission checks still apply after handoff. Use a fresh verifier
when restoring a binding in another process; no serialized binding or reconstructed principal substitutes for it.

## Synchronized bounded admission

`Service\AgentContinuationFence::synchronized(AgentId, AgentContinuationScope, Closure): void` is a **required trusted
consumer port**, not a no-op implementation supplied by AccessControl. It acquires the complete qualified fence before
invoking its callback exactly once synchronously, and releases in `finally` only after callback completion/failure.
Unsupported, incomplete or unverifiable participation must throw **before** invoking the callback. No startup-ready
flag, sequential `getById()`/`getByIds()` calls or detached Boolean implements this capability.

Under that fence the guard:

1. Checks the existing Agent operation cohort contract, including current restoration reconciliation.
2. Reads the authoritative current Agent, checks exact Agent identity and `Agent::hasActiveCredential()` against the
   **original** credential ID/revision, and requires ACTIVE state. Missing/revoked/rotated/replaced authority denies.
3. Uses the existing exact Permission resolver for the complete current direct assignment set. Missing, duplicate,
   extra or mismatched definitions fail closed; every definition must remain `ADMIN_SAFE`. Initial assignment revisions
   and Permission snapshots grant nothing. Every supplied canonical `PermissionName` must hold conjunctively; empty,
   malformed or non-list requirements deny. New grants/regrants and changed definition names affect the next check.
4. Calls `Service\AgentContinuationScope::isAllowed($freshPrincipal)` for current trusted consumer relationship policy.
   Target selectors, direct Permissions, Role labels and earlier input acceptance are not relationship grants.
5. Samples current time **after** scope resolution. With valid original provenance, sets local admission and immediately
   invokes the action inside the still-held fence. This successful final sample/check is the admission point.

The fence coordinates **all** relevant Agent credential/assignment/cohort writers, Permission definition/reference
writers and the supplied scope's authorities, including direct writes, deletion, restore/replacement and external
policy changes. Existing repositories must provide fresh post-acquisition state, not a stale identity map or replica.
Consumers choose and qualify shared locks/transactions or an equivalent protocol, global lock ordering, connection
and cohort participation, actual scope/action association and finite action duration. Existing Agent and Permission
repository write fences must participate; a second unrelated lock protecting only readers is insufficient. External
scope authorities which cannot join this boundary make that composition unsupported, not permissively allowed.

A writer winning before acquisition changes the checks and can deny the action. A conflicting writer arriving during
the callback must wait/reject until the bounded action completes and protection releases. A writer winning after
release affects the next action. Time expiry after the final sample is not a completion deadline or an interruption
mechanism. The fence does not retroactively retract bytes or cancel work. Actual data/effect transaction, input CAS,
consumption identity, idempotency, retry and recovery remain consumer-owned. Do not silently retry an action on failure.

Use separate invocations for each queued disclosure, background step and consumption of retained accepted input.
Prepare/queue work without disclosing it, then guard the actual output/effect/consumption. If a callback merely queues
future work or starts an async operation, it admits only that bounded enqueue/start; the later protected effect needs
its own admission. The callback cannot return a Boolean to authorize later bytes. It must not yield or release the
fence before the protected boundary. Saved callbacks are deactivated after `run()` returns and cannot invoke the
action again; repeated callback attempts never perform a second action. A broken trusted fence may already have
allowed the first action before a subsequent protocol fault: that fault is not rollback proof.

## Outcomes and error ownership

Before action invocation, denial, invalid provenance, resolver/clock/scope faults, incompatible cohorts and unavailable
synchronization all produce `Exception\AgentContinuationRejectedException` with exactly **`Agent continuation
unavailable.`**, code zero and no previous cause. There is no fallback to an earlier allow or partial Permission set,
no per-frame HMAC verification/nonce consume, business mutation, commit or new Domain event. Acquiring a fence is
resource participation, not a business grant.

Once the action begins, action/fence faults propagate to the consumer's existing redaction-aware safe error owner.
They are **not** converted into a fabricated pre-effect denial; an exception is not proof that an effect never happened.
Keep one reporting owner. Never publish raw throwables, binding/context contents, signed requests, nonce/credential
material, Permission/scope explanations or captured closures. Sensitive parameters and redacted debug projections
help accidental traces; they are not universal log sanitization. Consumers translate the neutral outcome through the
actual protocol's concealment/error contract, not a new universal HTTP or MCP envelope.

Denial grants no further admission. It proves neither a worker stopped nor rollback, compensation, forced job
cancellation, exactly-once delivery/consumption, removal of already disclosed bytes or reversal of earlier input
consumption. A later successful recheck does not restore consumed input or authorize a different work target.

## Evidence, dependencies and remaining qualification

`AgentContinuationGuardTest` exercises actual request admission, nonce consumption, Domain Agent successors and the
real exact Permission resolver through controlled repository/scope/fence ports. Separate queued-output, background-step
and retained-input probes assert suppressed second effects after authority/scope changes and faults. Shared-writer
schedules cover changes before acquisition, attempted revocation/rotation/assignment/definition/scope writes between
checks and action, and writes after release. Binding tests cover rejected/incomplete authentication, copied principals,
serialization/hydration, worker verification failure, unchanged original age, exact expiry and scope-time expiry.
Ordinary request snapshot/replay behavior remains tested; repeats perform no extra authentication or nonce consumption.

These are **controlled package schedules**, not real SQL/distributed concurrency, production cryptography, actual
worker restart, queue-integrity, scope-authority, clock or container-lifetime qualification. Before adoption, qualify
those exact real components, every writer and alternate entry point, lock loss/timeouts/fault cleanup, restore fencing,
finite action/effect boundaries and retained-input CAS/recovery. The package cannot verify that an arbitrary supplied
port tells the truth. Qualification and trusted injection are mandatory; no production-safe-race claim follows from
these fixtures. Independent technical Review and behavioral QA remain distinct acceptance stages.

New continuation types import no new Fight Common declarations. The existing `^1.3` dependency remains unchanged;
original request admission still uses the actual tagged v1.3.0 `TransactionalUnitOfWork::commitTransactional(callable)`
contract (`7de6cad6e8a9752973ad9f8e27e285b0c1510582`). No Common Subscription/Task signatures, development snapshot or
new runtime prerequisite is assumed. Actual Common live/Task integration belongs to TICKET-00030/00031. This additive
API has no new business persisted schema, compatibility alias, legacy reader or migration. The combined EPIC release
and its unassigned version remain unchanged; implementation is not publication, release or consumer adoption.
