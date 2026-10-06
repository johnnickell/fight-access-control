# Recoverable Agent rotation (v0.5.0)

[TASK-00048](../planning/tasks/00048-TASK.md) replaces raw-return rotation with an authorized, retained-key
operation. It consumes [provisioning correlation](agent-provisioning-operations.md),
[atomic predecessor retirement](agent-credential-retirement.md) and the existing
[protected delivery attempt](agent-credential-delivery.md). This is not release or consumer qualification:
[discovery/restart recovery](agent-delivery-recovery.md), [maintenance](agent-delivery-maintenance.md) and
[issuance conformance](agent-issuance-conformance.md) now exercise the replacement together.
[Delivery conformance](agent-delivery-conformance.md) and mandatory [cohort guards](agent-operation-cohorts.md) also
cover the actual paths. [Restoration guards](agent-restoration-safety.md) are implemented at the package boundary;
[current integration and evidence](agent-integration.md) distinguish this from unexecuted real consumer qualification.

## Public API and composition

Use `AgentCredentialRotationService::rotate(AgentOperationKey, AgentRotationRequest)` with a key persisted by the
caller **before** invocation. The request contains the target Agent ID, original expected credential ID and revision,
and a registered destination identity/binding revision. The scope comes from trusted authenticated context. No raw
secret, URL, path, caller-computed digest or implicit operation identity is accepted.

```php
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationId;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentOperationKey;
use Fight\AccessControl\Domain\AccessControl\Agent\Operation\AgentRotationRequest;

$key = new AgentOperationKey($trustedScope, AgentOperationId::generate());
$request = new AgentRotationRequest($agentId, $currentCredentialId, $currentRevision, $registeredDestination);
// Persist both key and ORIGINAL request before invoking the composed service.
$result = $rotationService->rotate($key, $request);
// On response loss or indeterminate commit, retry this same key AND original predecessor request.
// Do not replace it with the now-current credential, create another key, or infer rollback.
```

`AgentCredentialRotationResult` now contains only `isConfirmed()`, nullable `getIssuance()` and nullable typed
`getWarning()`. Confirmed issuance identifies the original Agent/credential/revision, delivery ID, destination and
write order. It is not proof of delivery, enrollment activation or launch/use permission. No secret getter remains;
the old public raw-result constructor is gone. Safe results contain no ciphertext or decryption handle.

Inject the same capabilities as provisioning: `AgentRepository`, `AgentOperationRepository`,
`AuditEvidenceRepository`, `AgentOperationAuthorization`, `HmacSharedSecretGenerator`, `HmacSharedSecretCipher`,
`AgentDeliveryCipher`, `Clock`, `TransactionalUnitOfWork`, `EventDispatcher`, and optional `AgentOperationLimits`.
The new mandatory `AgentOperationAuthorization::authorizeRotation(scope, destination, target)` authenticates the real
invoker and fences current scope, explicit delegation, target authority and destination ownership **before key lookup**.
It returns only a bounded safe audit identity. Every retry, including collision resolution and retired original
issuance, checks current authority again. Authorizing a retry must not require its original predecessor still current.
No actor string, cached allow, outer transaction or nonparticipating external policy store substitutes for this port.

The old `AgentCredentialLifecycleService::rotate()` and aggregate `rotateCredential()` are removed, not callable
rejection stubs. `AgentCredentialLifecycleService` accepts Agent repository, audit repository, Clock, Unit of Work
and event dispatcher. Its `revoke()` behavior, including publication-failure rethrow, is unchanged. The
[current Agent contract](agent-current-contract.md) has no legacy/adoption mode or recovery marker.

## Request binding and transaction

The single supported canonical contract (marker `2`) uses the ordered JSON tuple `rotate`, target UUID, expected
credential UUID, expected revision, destination UUID, destination binding revision. Expected revision is an integer
from 0 through `PHP_INT_MAX - 1`; destination revisions remain positive. UUID value objects normalize equivalent
representations. Existing keys validate their marker before comparison; unsupported markers reject and permanent
tombstones never become new work. Changed kind, target, predecessor identity/revision or destination binding conflicts
without issuance. No outcome-affecting per-request options exist. The [canonical contract](agent-canonical-upgrades.md)
has no historical reader or selectable creation version; `canonicalize()` takes no argument.

Inside one package-owned transaction:

1. Authorize current scope/target/destination and validate the safe audit identity.
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
6. Add the new pending operation/delivery and safe rotation audit; commit all state once. Capacity, cancellation,
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

Callback failures surface a sanitized `AgentOperationRejectedException` with no chained provider error. If the
callback finished but commit did not confirm, return indeterminate with no guessed issuance—even if persistence
actually committed. A fresh original-key retry reconciles either committed or rolled-back state.

Only a confirmed new rotation publishes `AgentCredentialRotated`, identified by its Agent/credential/revision tuple.
Publisher failure returns confirmed metadata plus `AgentPublicationWarning::PUBLICATION_FAILED`; failed publication
of the fixed, sanitized `AgentCredentialLifecycleFailed` evidence cannot replace that outcome. There is no outbox or
automatic event retry guarantee. Same-key retries emit no duplicate mutation/audit/fact. Durable operation state,
not notifications, owns recovery; [TASK-00049 conformance](agent-issuance-conformance.md) exercises both-publisher failure
and scheduler-only restart through TASK-00052's actual path.

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

| Requirement | Executable package evidence |
| --- | --- |
| I1 / I2 normal rotation and original-request restart | `AgentCredentialRotationServiceTest::test_rotation_commits_before_publication_and_recovers_after_restart` |
| I2 same/conflicting keys, lost CAS, independent winner | `test_concurrent_loser_rolls_back_then_resolves_only_the_authoritative_winner`, `test_absent_collision_winner_is_bounded_contention_and_never_reissues` |
| I2 / I6 changed requests, retained versions/tombstones | `test_changed_original_request_conflicts_and_unknown_versions_never_reissue`, `test_capacity_preserves_same_key_tombstones_and_rolls_back_a_rejected_new_rotation` |
| I3 rollback and both uncertain commits | `test_precommit_failures_roll_back_successor_retirement_reservation_and_audit`, `test_uncertain_commit_requires_original_request_reconciliation` |
| I4 both publishers fail | `test_both_publisher_failures_never_disguise_committed_issuance` |
| I5 authority, delegation, shared writer fence, slot reassignment | `test_current_authority_precedes_lookup`, `test_authorized_delegate_and_target_writer_share_fences_and_retries_observe_revocation`, `test_destination_reassignment_across_scopes_preserves_global_order_and_distinct_delivery` |
| I1 / I7 real delivery fences and successor bytes | `test_rotation_fences_real_delivery_and_late_staged_bytes_never_acknowledge`, `test_successor_delivery_wins_over_an_older_in_flight_invocation` |
| I7 current authentication and nonce races | `AgentRotationAuthenticationTest` interleaves actual rotation before/after nonce consumption, rejects old authority and successor replay |
| I7 Domain invariants and revocation failure semantics | `AgentRecoverableRotationTest`, `AgentCredentialLifecycleServiceTest`; existing revocation, Permission and authentication suites remain in the full gate |

These are deterministic transaction-aware in-memory interleavings and a noncryptographic cipher/sink fixture, not
parallel production database sessions, real cryptography, consumer activation/use or release qualification. The
full package gate checks exact statement coverage; coverage is not proof of consumer concurrency. There is no HTTP/UI
change, so executable persisted-state outcomes—not screenshots—are the before/after evidence.
