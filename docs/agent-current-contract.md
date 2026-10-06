# Current Agent contract (v0.5.0)

[ADR 0011](../planning/adr/0011-pre-v1-current-contract-only.md) selects one current pre-v1 API and persisted model.
TASK-00068 removes previous-iteration support; this is not a migration, release or consumer qualification.

## One authority model

Every Agent credential is correlated with its original retained credential operation. Provision through
`AgentProvisioningService`; rotate through `AgentCredentialRotationService`; revoke through
`AgentCredentialLifecycleService::revoke()`. The old lifecycle `rotate()` and aggregate `rotateCredential()` are
removed, not callable rejection stubs. The supported aggregate transition is `rotateRecoverableCredential()`;
constructing an immutable successor alone does not persist or authorize issuance.

There is no recoverable/non-recoverable flag, default, getter or View/schema field. Provisioning, name and Permission changes,
rotation, revocation and hydration all use the same model. Administrative `AgentView` contains Agent ID, name, state,
credential ID/revision, Permission-assignment revision and resolved Permissions. It grants no authority and exposes
no authentication envelope or delivery material. Original issuance, delivery and credential disposition remain
separate in the authorized operation status query.

## Hydration and write integrity

`Agent::reconstitute()` restores the complete current persisted authority: ID, name, lifecycle state, credential
ID/revision, encrypted authentication envelope, Permission IDs/assignment revision, creation and update timestamps.
It rejects negative credential revisions, nonpositive assignment revisions, empty envelopes, backdated updates and
non-list/duplicate Permission IDs. It neither generates credentials nor repairs unknown state. Never use `provision()`
to hydrate authority: that would reset revisions, terminal state and timestamps.

Consumer repositories must additionally validate exact persisted operation correlation, uniqueness and shared
transaction participation. Missing or ambiguous correlation is an error, never permission to infer issuance or skip
cancellation. Every lifecycle replacement, including direct repository calls, validates the exact Domain successor and
atomically cancels the predecessor operation under the shared credential/delivery/destination/authority fences.
Rotation commits the new operation, separate delivery material and audit in that transaction. Revocation retains the
original correlation and records retirement with audit. Permission changes preserve the credential tuple/correlation.
New `add()` is insertion only, never an upsert over existing or revoked authority.

[Name updates](agent-name-updates.md) use `UpdateAgent` with typed User/Agent provenance and a mandatory
`AgentRepository::rename(id, name, now)` name-only capability. The repository samples the supplied clock callback
only after current writer admission, applies `Agent::rename()` to fenced ACTIVE state, and returns the exact persisted
time or null for a validated no-op. All authority and exact operation correlation are preserved. Last committed
name wins without an expected old name or name revision; stale whole-Agent writers cannot undo it. No-op changes
neither timestamp nor persistence and publishes no fact. A real change publishes `AgentNameChanged` after commit;
publication failure rethrows without undoing the rename. Consumers protect every entry point; provenance grants no
authority. Successful void dispatch permits only an input-derived `{agent_id, name}` acknowledgement, not a fresh
read claim. MCP profile Tool binding remains separate.

All writers validate the [cohort contract](agent-operation-cohorts.md) and hold its fence through commit. Repository
implementations redact sensitive Agent parameters and transaction callbacks as required by the
[retirement contract](agent-credential-retirement.md#composition-and-atomic-cancellation).

## Retained safety, not historical support

Current-contract authentication/nonce fencing, caller and target authorization, transaction rollback, uncertain-commit
resolution, same-key restart, original outcomes, receipt matching, destination ordering and permanent tombstones remain
mandatory. Cleanup cannot make an old operation key reusable. Unsupported canonical markers reject without historical
readers or fallback. Cipher/key identities, protocol versions and monotonic generations have current security purposes;
they are not selectable previous package contracts.

[Restoration safety](agent-restoration-safety.md) is implemented and independently accepted at the package boundary.
Use the [integration guide](agent-integration.md) and [final evidence inventory](agent-operation-evidence.md) for
current composition and qualification gaps; TASK-00059's guidance has independent acceptance and behavioral QA.
There is no historical data migration/backfill or cross-version rollback obligation, and no permission to reset consumer data. Actual
database, key, sink, authority-writer and activation/use qualification is separate from package tests.

## Package evidence

- `AgentReconstitutionTest`: exact persisted authority and invalid-state rejection, revisions and terminal lifecycle.
- `AgentHydrationTest`: actual provision/revocation followed by reconstitution, secret-free queries and active/revoked
  authentication without new issuance or mutation.
- `AgentComponentsTest`: generated public schema matches current safe payloads and collection/envelope references.
- `AgentRenameTest`, `UpdateAgentHandlerTest`, `AgentNameComponentsTest`: name-only transitions, typed provenance,
  fenced no-ops/concurrent writers, rollback/publication failures and generated command/provenance schemas.
- `AgentCredentialRetirementTest`: every direct/service cancellation path, missing correlation, unavailable
  composition, rollback, redacted failures and stale successors.
- `AgentCredentialRotationServiceTest`, `AgentRotationAuthenticationTest`, issuance/delivery/cohort/canonical
  conformance: retained-key restart, uncertain commits, authorization, nonce races, ordering and cleanup defenses.

These use controlled transaction-aware adapters, not a production database or cryptographic sink certification.
