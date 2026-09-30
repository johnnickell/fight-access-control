# Recoverable Agent provisioning (unreleased v0.5.0 work)

TASK-00046 replaces `AgentProvisioningService::provision(actorId, name)` with a request and retained scoped key.
TASK-00047 adds [authorized safe operation status](agent-operation-status.md) without an issuance transaction,
material access, capacity admission or side effects; original issuance remains separate from current disposition.
This is an **unreleased partial implementation**, not a supported deployable credential-delivery composition.
TASK-00050 adds [atomic credential retirement](agent-credential-retirement.md) through service and direct repository
writes. TASK-00051 adds the [protected delivery attempt](agent-credential-delivery.md), including separately committed
admission and fenced receipt acknowledgement. TASK-00048 adds [recoverable rotation](agent-rotation-operations.md).
TASK-00052/00053 own discovery/restart recovery and maintenance. TASK-00056 adds mandatory
[persisted cohort and capability guards](agent-operation-cohorts.md) before issuance/resolution; real consumer
qualification and remaining restoration work are separate.
No consumer adapter is supplied or qualified here.

## Public boundary

- `AgentOperationKey` binds a caller-generated `AgentOperationId` (Common `UniqueId`) to a trusted namespace,
  originating caller type and stable caller ID. These strings identify scope, not authority. The consumer must derive
  them from trusted application context, not accept an arbitrary caller-selected authority scope.
- `AgentProvisioningRequest` contains only a name and registered `AgentCredentialDestination` (UUID and binding
  revision). No URL, path, caller digest, secret or unspecified options are accepted. Marker `2` uses fixed Unicode
  edge trimming and case-sensitive name equality; request kind is always `provision`.
- Inject request-scoped `AgentOperationAuthorization`. It authenticates the real invoker, authorizes the originating
  scope (including explicit current delegation) and destination, and holds same-connection authority and ownership
  fences until transaction completion. It returns the authenticated audit actor ID. It neither starts nor commits
  transactions. Boolean allows, actor strings and cached external decisions are not substitutes. Unsupported
  compositions reject. Delegation never rewrites the original key.
- `AgentProvisioningResult` is safe metadata: confirmed original `AgentIssuance` or indeterminate with no guessed
  identity. A typed publication warning can accompany only confirmed issuance. Neither result nor issuance confirms
  delivery, enrollment activation or permission to launch. Those require their own confirmed outcomes and current
  authorization. There is no raw-secret result or general secret-read method.

The consumer composition root constructs `AgentProvisioningService` with `AgentRepository`,
`AgentOperationRepository`, `AuditEvidenceRepository`, `AgentOperationAuthorization`, `HmacSharedSecretGenerator`,
`HmacSharedSecretCipher`, `AgentDeliveryCipher`, `Clock`, `TransactionalUnitOfWork`, `EventDispatcher`, and optionally
`AgentOperationLimits`. None of these capabilities is optional; omitting limits alone selects defaults.

```php
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentProvisioningRequest;

// Persist this key with the initiating workflow BEFORE invoking the composed service.
$key = new AgentOperationKey($trustedOriginatingScope, AgentOperationId::generate());
$request = new AgentProvisioningRequest('Deployment worker', $registeredDestination);
$result = $service->provision($key, $request);
if (!$result->isConfirmed()) {
    // Keep this exact key/request for authoritative resolution after storage recovers.
    // Do not create another key or report rollback, delivery, activation or launch permission.
}
// Confirmed getIssuance() is safe original metadata; getWarning() is a typed optional publication warning.
// A later retry calls provision($key, $request) with fresh authenticated composition.
```

`AgentOperationRejectedException::getReason()` returns a sanitized enum. `isRetryable()` identifies capacity,
contention and unavailable capability/storage rejections. A retry always uses the retained key. Conflicting or unknown
canonical requests need correction, not guessed issuance. No implicit operation ID or destination is manufactured.

## Transaction, persistence and concurrency

One Common `TransactionalUnitOfWork` owns authorization, operation resolution or new issuance, Agent persistence,
separate delivery encryption, destination write reservation and safe audit. All capabilities must share its connection.
The operation repository reads authoritative state, never an eventually consistent replica. Scope authorization occurs
before key lookup, including retry and collision resolution. Existing keys validate the sole supported marker `2`
before comparing the request; unsupported markers reject without fallback. Unseen keys use that same fixed
Unicode-aware normalization, for both binding and Agent name. Retained bindings are never rewritten; there is no
historical reader or runtime version choice. See the [canonical contract](agent-canonical-upgrades.md).

`AgentOperationRepository` owns operation persistence and destination write reservations. Reserve monotonically
increasing versions under the destination ownership fence shared with reassignment; the counter belongs to the stable
slot UUID, not its binding revision, Agent, scope or operation. Failed transactions undo reservations. Never reset
committed high-water marks. `add` enforces scoped key and globally unique delivery ID constraints and capacity under
transaction-duration fences. It raises typed collision only for the scoped-key uniqueness race; other persistence
errors must not masquerade as a key winner. A collision rolls back the entire loser, then authorizes and reads once in
a fresh transaction. A missing winner is retryable contention, never automatic new issuance. Closed Unit of Work
instances cannot be reused; the caller recomposes and retries the original key. At most two transactions per call.

Operation records retain the canonical version/request, original issuance and recorded delivery/credential
dispositions forever (or in equivalent secret-free permanent tombstones). TASK-00047's read contract adds
`AgentOperationRepository::getStatusByKey()` and `AgentOperationAuthorization::authorizeRead()`; consumer
implementations must provide both, with no permissive compatibility default. They own a separately encrypted prepared delivery copy, with consumer key-version identity.
Cleanup cannot remove correlation or make a retained key fresh. The original tuple remains distinct from delivery
state. Repository replacement for downstream lifecycle/claim work must CAS the complete expected operation while
sharing Agent lifecycle, authority and destination fences. Retirement must atomically remove the delivery copy with
credential retirement without cipher/sink access. All adapters and direct writers must honor those fences.

Every Agent uses the [current correlated model](agent-current-contract.md), with no legacy mode or recovery marker.
Revocation constructs a terminal successor whose persistence requires atomic original-operation cancellation via
`AgentRepository::replace()` and `AgentOperationRepository::retireCredential()`. Persist operation state revisions
and reject stale delivery writes; see the [retirement contract](agent-credential-retirement.md). Retired aggregate and
raw-return lifecycle rotation APIs are removed. Validated hydration and Permission assignments preserve authority and
correlation; missing cancellation support fails closed. This is not permission to deploy the intermediate composition.

## Sensitive material

`AgentDeliveryCipher` encrypts raw material with authenticated associated data from the exact `AgentIssuance` tuple:
operation scope/key, delivery UUID, Agent/credential UUID and revision, destination UUID/binding revision/write order,
and issuance time. Store that binding, key version and ciphertext together. Swaps must fail authenticated decryption
in the later delivery path. Authentication encryption is independent and never a delivery fallback. Cipher adapters
must not publish provider details. Prepared material is non-serializable and redacted in debug output; repository
adapters explicitly persist it. No sink call occurs during provisioning. The worker's sensitive invocation and
admission contract are downstream, not inferred from having prepared material.

## Failure and publication

A callback failure is pre-commit rejection. Arbitrary dependency failures become sanitized storage/integration
rejection without the original exception, trace arguments or provider details in public diagnostics. A failure after
the callback completed but before `commitTransactional` returned is **indeterminate**, regardless of whether the
adapter actually committed or rolled back. The caller retains the original key; an exception cannot prove rollback.
Adapters must propagate callback failures and must not retry callbacks invisibly or return before confirmed commit.

Only confirmed new issuance publishes `AgentProvisioned`. The Agent ID uniquely identifies that provision fact;
service retry does not publish it again. There is no automatic notification retry/outbox guarantee. Publication failure
returns `PUBLICATION_FAILED`; even failed failure-evidence publication cannot disguise the committed outcome.
Indeterminate and pre-commit failure publish only sanitized failure evidence, never a success fact. Durable operation
and prepared state survive absent notifications; [TASK-00049 conformance](agent-issuance-conformance.md) exercises
scheduler-only discovery with TASK-00052's actual path, not merely this slice's prepared-state fixtures. Failure evidence contains no request strings or arbitrary provider messages.

## Finite bounds

| Input/admission | Default | Valid override |
| --- | --- | --- |
| Raw provisioning-name bytes for new keys | 512 | 128–4096 |
| Pending operations per originating scope | 100 | 1–10000 |
| Pending operations across the repository | 10000 | 1–1000000; at least the per-scope bound |

Names retain `AgentName`'s non-empty, 120-character normalized limit. Absolute request ingress bound is 4096 bytes;
namespace is 1–64 ASCII identifier characters, caller type 1–32, stable caller ID 1–128. IDs are UUIDs; revisions
and slot write versions are positive integers. Limits are optional validated values, not routine approval gates.
Adapters serialize admission across all writers using one cohort's configured limits. Capacity failure is retryable
and rolls back all issuance state; it does not evict tombstones. Existing-key resolution precedes new-key limits,
so lowering capacity or the new-name limit cannot block resolution of a retained valid request. Pending work counts
until its delivery copy is terminally retired. Downstream recovery requires independently bounded worker policies;
new issuance capacity must never gate existing-operation status, claims or recovery.

## Package evidence and consumer qualification

`AgentOperationTest` proves values, canonicalization, bounds, safe material and tombstone identity.
`AgentProvisioningServiceTest` proves observable persisted outcomes using transaction-aware Domain repository and
consumer-capability doubles. `ProvisioningEnvironment`, `InMemoryAgentOperationRepository`,
`InMemoryAgentOperationAuthorization`, `BoundAgentDeliveryCipher` and `UncertainAgentUnitOfWork` are reusable test
fixtures under `tests/Application/AccessControl/Agent/`, not production adapters. The cipher fixture is deliberately
not cryptography. Consumers must independently qualify authenticated encryption and the actual shared connection,
locking, unique constraints and concurrent transactions before adoption. TASK-00049 now exposes the
[cross-operation consumer-bindable suite](agent-issuance-conformance.md), separately from these focused fixtures.

| Requirement | Focused package evidence |
| --- | --- |
| I1, I7 complete issuance and safe result | `test_it_commits_one_complete_issuance_before_publication_and_returns_no_secret` |
| I2 loss/restart, equivalent canonical request | `test_lost_response_and_service_restart_resolve_without_generation_audit_or_another_fact` |
| I2 concurrent identical/conflicting keys | `test_unique_loser_rolls_back_then_resolves_or_conflicts_with_winner` |
| I2 changed fields/unknown version | `test_changed_binding_or_unknown_version_rejects_without_generation` |
| I3 uncertain committed/rolled-back outcomes | `test_uncertain_commit_never_guesses_and_restart_resolves_the_original_key` |
| I3 pre-commit rollback across all write/encryption boundaries | `test_precommit_faults_roll_back_every_record_and_never_expose_provider_details` |
| I4 success/both publisher failure | `test_publication_failure_preserves_committed_work_and_returns_only_a_typed_warning` |
| I5 denied new/retry before lookup | `test_current_authority_denies_before_lookup` |
| I5 shared authority fence/delegated identity | `test_authorization_writer_waits_for_issuance_and_next_retry_observes_revocation` |
| I5 authority capability rollback | `test_authorization_participant_failure_rolls_back_its_own_writes` |
| I5 cross-scope delivery identity and reassigned-slot order | `test_cross_scope_ids_and_reassignment_preserve_delivery_identity_and_slot_order` |
| I6 default/override bounds, capacity isolation and retained keys | `AgentOperationTest` and `test_capacity_rejects_new_work_but_preserves_resolution_under_lowered_limits_and_tombstones` |
| I7 mandatory cancellation after Permission changes | `test_recoverable_agent_rejects_unfenced_lifecycle_even_after_permission_changes`, `AgentCredentialRetirementTest` |

Interleavings model a winner becoming visible after loser rollback and an authority writer waiting for the shared
fence. They are not parallel database sessions or PostgreSQL proof. No no-caller scheduler, sink, activation/use,
real encryption adapter, release or consumer-adoption result is claimed here. Current Agent authentication,
Permission, hydration and lifecycle tests remain part of the complete package gate.
