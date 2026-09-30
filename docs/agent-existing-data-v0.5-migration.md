# Existing Agent data and API compatibility (unreleased v0.5.0)

[TASK-00055](../planning/tasks/00055-TASK.md) implements the M1 existing-data/API slice of
[TICKET-00014](../planning/tickets/00014-TICKET.md). It preserves existing authentication authority and permits a
known active legacy Agent to enter recoverable issuance through an **explicit new authorized rotation**. It is not a
migration runner, consumer qualification, release or permission to deploy the incomplete replacement.
[TASK-00056's cohort contract](agent-operation-cohorts.md) now adds mandatory repository compatibility and writer
fences; canonicalization, restoration and final migration guidance remain TASK-00057–00059 work.

## Preserve existing authority; do not invent history

Consumers own schema conversion and adapter hydration. Inventory known existing records before switching writers.
Preserve each Agent ID, name, lifecycle state, current credential ID/revision, encrypted authentication envelope,
Permission IDs/assignment revision and creation/update timestamps exactly. Do not call `provision()` to hydrate an
existing row: it resets state, revisions and timestamps. Revoked records stay revoked; upgrade does not generate,
rotate, copy material, deliver, publish, audit issuance or change Permission authority.

The existing persisted `recoverableCredentialOperation` boolean has explicit meaning:

| Persisted marker | Meaning | Upgrade behavior |
| --- | --- | --- |
| `false` | Known legacy/non-recoverable current credential; historical operation correlation is unavailable | Preserve authority and no operation/delivery records; authentication remains subject to normal current-state/nonce checks. |
| `true` | Current credential was issued through a correlated operation; retain its original binding even after retirement | Preserve the marker and correlated operation/tombstone; never downgrade when lookup fails or material is gone. |
| Missing/unknown/inconsistent in a new-contract record | Unsupported or corrupt state, not evidence of legacy provenance | Reject hydration/admission and reconcile; do not silently coerce or default. |

Only the consumer's verified pre-replacement inventory may initialize known old rows to `false`. A migration version
or this marker is **not** a confirmed historical issuance, delivery, enrollment activation or permission to use a
credential. Do not backfill operation keys, request versions, delivery IDs, destination bindings, receipts or slot
orders from display names (not unique), audit rows or guesses. Do not copy an authentication envelope into delivery
storage. A missing retained operation on a supposedly recoverable credential is an error, not a legacy fallback.

`Agent::reconstitute()` accepts all persisted authority explicitly, including the required boolean marker:

```php
$agent = Agent::reconstitute(
    $id,
    $name,
    $state,
    $credentialId,
    $credentialRevision,
    $encryptedAuthenticationEnvelope,
    $permissionIds,
    $permissionAssignmentRevision,
    $createdAt,
    $updatedAt,
    $verifiedRecoverableMarker
);
```

This pure factory preserves inputs and rejects negative credential revision, nonpositive Permission-assignment
revision, empty envelope, update time before creation, non-list or duplicate Permission identities. It does not
normalize invalid history, decrypt, consult persistence or synthesize correlation. It cannot verify envelope
cryptography or cross-record consistency: adapters must also validate recognized persisted types/markers, current
Permission definitions and exact operation/credential bindings under their normal storage contract. Equivalent
validated adapter hydration through the protected constructor remains possible; this does not waive those checks.
Preserve `#[SensitiveParameter]` on envelope-bearing forwarding methods and redact row parameters and provider
exceptions. Never present or log the material-bearing aggregate as a safe result.

## Safe reads and the public API change

`GetAgentById` and `ListAgents` now project `AgentView::hasRecoverableCredentialOperation()` and the required boolean
`recoverable_credential_operation` in `toArray()`. Direct `AgentView` constructors must supply the new final boolean
argument; there is deliberately no default that guesses legacy state. Prefer `AgentView::fromAgent()` with resolved
Permission snapshots. All prior fields retain their meaning and no secret, ciphertext, operation key, destination
path or secret-read capability is added. Administrative entry-point authorization remains consumer-owned.

The shipped `Fight.AccessControl.Agent` OpenAPI component requires the same boolean, including through existing
collection and optional JSend references. This required-field/constructor change belongs to the already planned
pre-1.0 breaking `v0.5.0` contract, not a patch to published versions. Consumer projections and strict schemas must
be updated together; published `v0.4.0` is unchanged. Authentication principal shapes are unchanged.

`GetAgentOperation` remains an independently authorized original-key query. It cannot discover historical issuance
from an Agent name or marker. With no retained operation it returns **indeterminate**, not confirmed legacy provision,
rollback or permission to provision another Agent. Reads do not mutate, commit or publish. Wrong scope, expired
delegation, denied target or destination still fail closed; see the [status contract](agent-operation-status.md).

## Explicit rotation is the only new recovery route

For a **known active** legacy Agent, a currently authorized caller retains a new scoped key and the exact original
request (target ID, expected current credential ID/revision and registered destination), then calls the existing
`AgentCredentialRotationService::rotate()`. No extra migration Command, service, permission name, manual configuration
or routine human-approval gate is introduced. Authorization still covers current caller/delegation, target and
destination inside the package-owned transaction.

The normal [rotation contract](agent-rotation-operations.md) applies unchanged except that the predecessor may now
be explicitly legacy:

1. Resolve a retained key under current authority before attempting a new mutation. Never reinterpret its predecessor.
2. Require an exact active current credential ID/revision. Stale, missing and revoked targets reject without generation.
3. Generate one **new** credential, separate authentication encryption and integrity-bound delivery material, and the
   new operation's original issuance. Advance credential revision exactly once; preserve Agent and Permission identity.
4. Persist the exact successor and change its marker from `false` to `true` in the same fenced transaction as the new
   operation, destination reservation and audit. There is **no predecessor operation to retire or fabricate**. Compatible
   same-connection operation persistence is mandatory for this transition, just as for already-recoverable rotation.
5. Commit once, then publish the ordinary rotation fact. No legacy provision/delivery fact or migration event is emitted.

`Agent::rotateRecoverableCredential()` owns successor construction; `canReplaceCredentialWith()` permits marker
promotion only with a new credential and next revision. Marker-only changes, revocation that changes the marker,
downgrades, unrelated authority changes and resurrection reject. `AgentRepository::replace()` must compare the entire
current predecessor and share credential/Permission/authority fences. For a recoverable predecessor it still requires
exact atomic original-operation cancellation; legacy status never bypasses those obligations on later rotations.

Pre-commit failure rolls back the marker, credential, operation, destination reservation and audit together. An
indeterminate commit remains indeterminate: recompose the service and retry the **same key and original request**.
A confirmed commit with publication failure returns safe metadata plus the typed warning, even if both publishers
fail. Retrying confirmed issuance does not generate or audit again. Existing capacity/default/override policy is
unchanged; no compatibility-specific limit or recovery approval is required.

The old credential immediately loses authentication authority. The historical legacy snapshot still means no original
operation existed; the new operation records only the successor and retains the original predecessor request. New
issuance starts pending delivery and does not establish delivery, enrollment activation or permission to launch/sign.
Use protected delivery and independently authorized current consumer activation/use checks, never the marker as an
allow decision. Revocation remains terminal and preserves its existing post-commit publication-failure behavior.

If earlier provisioning was ambiguous and the Agent/current credential cannot be established authoritatively, stop for
reconciliation. Neither a display-name match, audit event nor absent-key lookup may trigger automatic provision or
rotation. This is a consumer reconciliation obligation, not an inference the package can safely make and not an extra
approval for routine correlated recovery.

## Replaced calls remain closed

- `AgentProvisioningService::provision()` requires `AgentOperationKey` and `AgentProvisioningRequest`; the old actor/name
  raw-return signature is removed. Never invent a key or destination for it.
- `AgentCredentialLifecycleService::rotate(actorId, agentId, expectedCredentialId)` rejects `INVALID_REQUEST` before
  work for **all** Agents, including legacy records. Use the explicitly authorized replacement service.
- Provision/rotation results have no raw-secret getter. `AgentCredentialLifecycleService` no longer takes the obsolete
  generator/cipher constructor dependencies. Its revocation use case remains supported.
- Legacy aggregate transitions retained for state compatibility are not a supported alternative issuance workflow.
  Recoverable aggregates reject the old `rotateCredential()` method. Consumer composition must fence obsolete writers;
  new Domain code cannot stop an old deployed binary. TASK-00056 owns that cohort qualification.

Runtime rejection remains owned by TASK-00046/00048/00050. This slice adds no compatibility overload or escape path.

## M1 evidence and limits

| Observable contract | Executable evidence |
| --- | --- |
| Exact persisted state and explicit marker, invalid state rejects | `AgentReconstitutionTest` covers lifecycle states, both markers, counters, time, envelope and duplicate/non-list assignments. |
| Existing active authentication and terminal revoked denial; safe reads without issuance effects | `LegacyAgentCompatibilityTest::test_upgrade_reads_and_authentication_preserve_active_and_revoked_history` uses the real principal provider and QueryHandlers over reconstituted pre-replacement authority. |
| Only explicit new rotation creates correlation; same-key restart and secret-free pending outcome | `test_explicit_rotation_creates_only_new_recovery_and_retries_original_request_after_restart` checks one new operation/audit, separate successor bytes, no predecessor history, no duplicate issuance and fresh retry/status authorization. |
| Denied/stale/revoked/missing targets and rollback | `test_denied_stale_and_revoked_legacy_rotation_never_mutates_or_generates`, `test_failed_operation_persistence_rolls_back_the_legacy_transition`. |
| Uncertain committed/rolled-back outcomes and both publishers failing | `test_uncertain_commit_resolves_only_the_original_legacy_rotation`, `test_both_publishers_can_fail_without_disguising_the_committed_legacy_transition`. |
| Current-authority and nonce fences survive adoption | `test_legacy_rotation_fences_authentication_and_nonce_replay` interleaves actual rotation before/after nonce consumption, rejects retired credentials and successor nonce replay. |
| Raw-return APIs remain closed | `test_legacy_raw_service_rotation_remains_rejected_without_implicit_bindings`; retained `AgentProvisioningServiceTest` and `AgentCredentialLifecycleServiceTest` rejection cases. |
| Generated public schema matches both marker values | `AgentCompatibilityComponentsTest` compares the generated component to real safe payloads and checks collection/envelope references. |

These are modeled persisted-state package tests with transaction-aware behavioral repositories, controlled secret
cipher/verifier doubles and simulated commit outcomes. They do **not** qualify production cryptography, a real database
migration, concurrent processes, old-binary fencing or consumer activation/use. Consumer adapters must separately run
the [issuance](agent-issuance-conformance.md) and [delivery](agent-delivery-conformance.md) conformance suites, rehearse
schema conversion and prove all writer fences before adoption. No SQL/ORM, consumer repository, endpoint or migration
execution is supplied here. The TASK records actual gate results and review status.

Before/after evidence is nonvisual: at base `ea1e316`, the Domain required an existing recoverable marker for rotation
and administrative views omitted that marker. The new tests prove preserved legacy authentication plus explicitly
requested transition through the same service. There is no UI or product HTTP endpoint for useful screenshots.
