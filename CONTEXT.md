# Fight AccessControl Context

## Purpose

Fight AccessControl owns framework-neutral identity, credential, session, authorization, and account-lifecycle
behavior shared by Fight applications. The repository-local behavioral and security authority is
[TICKET-00001](planning/tickets/00001-TICKET.md).

## Vocabulary

- **User**: the stable human identity whose canonical email remains unique across pending, active, disabled, and
  deleted states.
- **Grant**: a purpose-bound, hashed, expiring, single-use credential for activation, password reset, or email
  change. Reissue revokes its predecessor.
- **Recoverable credential delivery**: package-owned activation, password-reset, or email-change work whose encrypted
  material commits with its grant. Workers discover secret-free due state, commit an exact leased claim, invoke a
  provider outside every transaction with the stable delivery-generation ID, then commit one typed expected-state
  outcome. Expired leases may repeat invocation, so the guarantee is at-least-once rather than exactly-once.
- **Refresh session**: authoritative server-side session state owning credential rotation, revocation, activity,
  lifetime, authentication version, and coarse device information.
- **Authenticated principal**: an immutable framework-neutral identity and authorization snapshot revalidated
  against authoritative storage once per request.
- **Agent**: a machine principal with direct Permission authority; it is not a User, refresh session, framework
  security user, or AI persona.
- **Agent credential**: one active HMAC authority belonging to an Agent. It has a public credential ID and a
  consumer-encrypted shared secret; the raw secret exists only when issued or during authentication verification.
- **Agent credential operation**: a caller-scoped, versioned request binding retaining original issuance metadata
  and a separately encrypted prepared delivery copy. Retrying an authorized retained key resolves that outcome;
  retiring its material never makes the key reusable. Current unreleased implementation supports provisioning and
  rotation of recoverably provisioned Agents; retries retain the original predecessor request.
- **Agent operation status**: an authorized secret-free read snapshot separating confirmed original issuance from
  recorded delivery and original-credential disposition. An absent record is indeterminate, not proof of rollback.
  Current scope/delegation/destination checks precede lookup and repeat with target checks before disclosure; reads
  neither commit nor access material and grant no delivery, activation or use authority.
- **Agent protected delivery**: one separately committed claim and current-authority admission, followed by an
  outside-transaction fixed-sink invocation and exact verified receipt acknowledgement. Original bytes/tuple and
  delivery ID survive retry/takeover; current credential, destination order, authority epochs and deadlines fence
  completion. Late admitted bytes remain inert, not enrollment activation or use permission. Current unreleased
  implementation delivers one exact provisioned or rotated operation; TASK-00052 adds bounded delegated discovery,
  scheduler passes and receipt-first restart recovery. Discovery excludes obsolete destination write reservations
  before limiting, so rejected predecessors cannot starve the current slot write. TASK-00053 adds maintenance.
- **Agent delivery maintenance**: authorized ciphertext-only rewrapping, original-retention expiry and separately
  confirmed inert sink cleanup. Keyset discovery includes obsolete slot copies; global key-reference counts are
  diagnostic, never permission to destroy keys. All material writers share key-version admission/reference fences.
  Cleanup excludes current delivered credentials and retains operation correlation, sink tombstones and slot order;
  physical key management and actual sink erasure remain consumer capabilities.
- **Agent issuance**: original Agent/credential/revision, global delivery ID and registered destination binding/write
  order. Confirmed issuance does not establish credential delivery, enrollment activation or permission to launch.
- **Agent credential revision**: the monotonically advancing version of an Agent's single credential authority.
  Rotation replaces the active credential at a new revision; revocation is terminal and removes authentication
  authority.
- **Agent direct Permission assignment**: an Agent-owned, duplicate-free set of stable Permission identities; it
  grants direct authority without introducing Agent Roles or a policy engine. A Permission cannot be removed while
  any Agent has it assigned.
- **Agent Permission-assignment revision**: the monotonically advancing version of an Agent's direct Permission
  assignment. It advances only when that set changes and is independent of the Agent credential revision.
- **Agent read result**: an immutable, secret-free record of an Agent's ID, lifecycle state, credential ID and
  revision, assigned Permissions by ID and canonical name, and Permission-assignment revision. It does not decide
  whether an action is allowed.
- **Authenticated Agent principal**: an immutable authoritative Agent identity and direct-Permission snapshot,
  resolved as one authentication flow rather than from an `AgentView` or a follow-up query.
- **Current Agent principal provider**: a consumer-composed, request-scoped module that authenticates one signed
  Agent request and returns its cached immutable Authenticated Agent principal for that request. Authentication,
  authority revalidation, Permission snapshot resolution, safe diagnostics, and request caching form one flow.
- **Agent-aware MCP tool availability**: an AccessControl-owned, request-scoped decision that maps a Fight Common
  canonical tool name to static required-Permission metadata and checks it against one current Authenticated Agent
  principal snapshot. Common receives only available or unavailable; later MCP requests resolve current authority
  again, and unavailable and unknown tools remain publicly indistinguishable.
- **Security context**: one request-specific, consumer-selected Authenticated User or Authenticated Agent authority.
  It is constructed with exactly that one authority and provides the common Permission and Role checks used by
  consuming code. Consumers select the authentication path through their framework adapters; the package does not
  inspect transports or choose between User and Agent authentication. Every authenticated authority exposes whether
  it is a User or Agent through the package-owned authenticated-principal type. Authenticated Agents have direct
  Permissions and no package-level Roles.
- **Principal Permission**: the narrow immutable Permission identity and canonical name captured in either an
  Authenticated User or Authenticated Agent snapshot. It is authorization data, not the authoritative Permission
  aggregate or the richer administrative Permission view.
- **Desired authorization state**: assigning or granting authority that is already present, or removing or revoking
  authority that is already absent, succeeds as an idempotent no-op. A no-op does not write persistence, advance an
  authority revision, or publish a success event. Missing identities, invalid definitions, stale expected revisions,
  and violated invariants still fail hard. Complete-set inputs are normalized as sets, so repeated identities in one
  request do not change the desired state or cause a failure.
- **Agent authentication diagnostic**: a secret-free, server-observable classification and correlation identifier
  for a failed Agent authentication. It is not disclosed to an untrusted caller.
- **Signed Agent request**: the transport-neutral representation of the Fight Common HMAC v1 canonical request;
  consumer applications map transport data into and out of this representation.
- **Managed Permission and Managed Role**: stable version-controlled authorization definitions reconciled
  exactly and atomically.
- **Super Admin Role**: the uniquely authoritative managed Role named exactly `ROLE_SUPER_ADMIN`; its name is
  reserved and cannot be used by a custom Role. Only this Role may hold protected Permissions. User Role assignment
  and removal use ordinary revision and reference semantics, including pending-User bootstrap; application builders
  authorize all caller entry points and protect last-admin removal.
- **Permission tier**: the non-null classification of a Permission. `ADMIN_SAFE` makes it eligible for delegation
  through consumer-protected command entry points; `SUPER_ADMIN_ONLY` reserves its authority for the designated managed
  Role and human Users.
- **Custom Permission**: a runtime-owned Permission classified `ADMIN_SAFE` from creation. It cannot carry the
  protected tier; delegation still requires authority over the actor and target.
- **Protected managed Permission**: a managed Permission the consuming project classifies `SUPER_ADMIN_ONLY`. The
  accepted v0.4.0 policy reserves its membership for managed `ROLE_SUPER_ADMIN` and its authority for human Users
  through that Role; policy definitions and promotion reject forbidden Role and Agent membership. Consumers fence
  promotion and grants through their persistence adapters.
- **Conformance suite**: reusable tests of observable Domain and Application outcomes which consumer repositories
  bind to their own adapters.
- **OpenAPI schema component**: an opt-in reusable description of a package-owned payload that a consumer's OpenAPI
  generator scans and incorporates into its own document. It does not create an endpoint, HTTP response, or runtime
  transport contract. Its stable OpenAPI key begins with `Fight.AccessControl.` and does not need to match its PHP
  class name.
- **OpenAPI schema contract**: the stable name and validation shape of a published schema component. It is a public
  package API even though consumers choose where and how to reference it.
- **OpenAPI credential-delivery values**: reusable worker-facing message and safe-result schemas, not endpoints.
  `CredentialDeliveryStatus` describes the operational PHP View (including required nullable history), while
  `DueCredentialDeliveries` is an unpaginated array. Invitation and operational statuses share the seven serialized
  Domain enum values. Generated contract tests run in the default PHPUnit/build pipeline and are reused by release
  composition qualification. The unreleased invitation enum correction requires a new minor release under ADR 0007;
  it does not change runtime delivery or authorization. See [composition guidance](docs/openapi-composition.md).
- **OpenAPI metadata distribution**: the package's non-autoloaded `openapi/` directory, containing schema-anchor
  attributes and a bootstrap file for a consumer-owned OpenAPI generator. It is neither Domain nor Application
  production code and does not define a package-owned document.
- **Browser authentication response**: the public authentication body containing access-token material and session
  metadata, but never a refresh credential. A consumer may deliver that credential through an `HttpOnly` cookie.
- **Portable token set response**: the authentication response for a non-browser client or an explicit body-token
  profile. It includes the sensitive opaque refresh credential as well as the browser-response fields.

## Package Boundary

Production dependencies flow `Domain <- Application`. Domain owns invariants and lifecycle state. Application
owns a synchronous secret-bearing authentication service, explicit non-sensitive commands and queries, portable
ports, immutable token/read results, and transaction orchestration. This package has no production Adapter layer and no
framework dependency. Consumers own clients, persistence, HTTP and cookie adapters, cryptographic keys, mail,
queues, realtime delivery, hosting, and runtime composition.

Application builders protect every command entry point using controls suited to HTTP, CLI, workers, or other
adapters. Consumer outer layers normally authenticate, validate, authorize, choose synchronous or asynchronous
invocation, and translate failures. Application and Domain code may therefore assume those outer checks succeeded
and fail hard when their own invariants are violated. For v0.4.0 planning, AccessControl retains its narrow
Application authorization ports for cross-user session access, email-change administration, and pending-invitation
correction, which concern ownership of a particular User's resource. The actor-only Role administration and User
Role-assignment and actor-only Agent Permission administration ports have been retired. Custom-Role grants and
direct Agent assignments require authoritative `ADMIN_SAFE` Permissions even for no-ops, fenced through their
respective repository writes. Consumer-managed caller Permissions and package-owned tier and managed-policy
invariants remain distinct.
Package-owned workflow coordinators are final implementation details marked `@internal`; consumers depend on the
public commands, services, authenticated principals, and Security context rather than implementing those coordinators.
Credential-delivery providers receive only one short-lived sensitive invocation after a committed claim and return a
typed outcome. Consumer schedulers use the package's secret-free due-work/status queries and direct delivery commands;
they do not copy claim, retry, terminalization, or stale-generation policy.

## Recoverable Agent operations — partial unreleased implementation

[EPIC-00009](planning/epics/00009-EPIC.md) records the separately planned breaking replacement for recoverable Agent
provisioning and rotation. John ratified D1–D4 and confirmed the complete destination and boundaries on 2026-09-27.
The approved TICKET decomposition assigns issuance/resolution to [TICKET-00012](planning/tickets/00012-TICKET.md),
protected delivery/recovery to [TICKET-00013](planning/tickets/00013-TICKET.md), and migration/compatibility plus
scenario traceability to [TICKET-00014](planning/tickets/00014-TICKET.md). TICKET-00012 now has approved
[TASK-00046](planning/tasks/00046-TASK.md) through [TASK-00049](planning/tasks/00049-TASK.md): provision, safe reads,
rotation and issuance conformance. TICKET-00013 now has approved [TASK-00050](planning/tasks/00050-TASK.md) through
[TASK-00054](planning/tasks/00054-TASK.md): retirement fences, protected delivery, restart recovery, material
maintenance and delivery/lifecycle conformance. TASK-00048/00049 now have concrete cross-TICKET prerequisites and
wait on unfinished dependencies rather than missing information. TICKET-00014 now has approved
[TASK-00055](planning/tasks/00055-TASK.md) through [TASK-00059](planning/tasks/00059-TASK.md): existing-Agent
compatibility, contract cohorts, canonical upgrades, restoration safety and migration/evidence guidance. All three
TICKET decompositions are complete; TASK-00046 is independently accepted and done for implementation/local
verification, not release; its PR #79 is now merged. John authorized TASK-00047 in the main checkout; its safe
status path is independently accepted and done for implementation/local verification, not merge or release.
John subsequently requested landing; PR #80 is merged into the current develop base. John authorized TASK-00050
in the main checkout on `feature/task-00050-retirement`. Independent re-review accepted its R1 failure-trace
redaction correction and complete retirement slice; TASK-00050 is done for implementation/local verification.
John authorized PR publication; PR #81 is now merged into `develop` at `6d52e8d`. John subsequently authorized
TASK-00051 in the main checkout on `feature/task-00051-protected-delivery`. Its protected delivery attempt is
independently accepted and done for implementation/local verification (840 tests / 7276 assertions, exact
5742/5742 statements). Review accepted `5f3f596` with 216 focused tests / 2232 assertions and verified all 674
full-gate inputs. John's landing request published
[PR #82](https://github.com/johnnickell/fight-access-control/pull/82) against `develop`; it is now merged at the
TASK-00048 base `4b6a554`. John authorized TASK-00048 in the main checkout on `feature/task-00048-rotation`.
Its [recoverable rotation](docs/agent-rotation-operations.md) now commits one correlated successor with atomic
predecessor cancellation, original-request resolution, current target authority and safe publication/uncertainty
outcomes. Independent review accepted `e667116` with all TASK criteria passing and no findings; TASK-00048 is
now done for implementation/local verification. John's landing request published
[PR #83](https://github.com/johnnickell/fight-access-control/pull/83) against `develop`; it is open at this checkpoint,
with no merge or release authorized.
John authorized TASK-00052 in the main checkout on `feature/task-00052-delivery-recovery`. Its
[discovery/recovery contract](docs/agent-delivery-recovery.md) adds read-only bounded selection, an event-independent
scheduler pass, optional receipt lookup before decryption, receipt-only recovery of current admissions and safe
reconciliation of lost delivered sink material. Independent review requested R1: obsolete slot reservations could
starve the bounded batch. The revision requires authoritative current-reservation selection before limiting, with
regression proof through the actual scheduler. Independent re-review accepted `c9e23ab`, with all criteria passing,
269 focused tests / 2140 assertions and all 696 full-gate inputs verified. TASK-00052 is done for implementation/local
verification. John requested landing; [PR #85](https://github.com/johnnickell/fight-access-control/pull/85) is open against
`develop` at its initial publication checkpoint after mechanical integration of the TASK-00060 planning-only base
update. The fresh landing gate passes 957 tests / 8350 assertions and exact 5971/5971 statements. This is not consumer
qualification, merge or release.
John authorized TASK-00053 in the main checkout on `feature/task-00053-delivery-material`. Its
[maintenance contract](docs/agent-delivery-maintenance.md) adds bounded read-only selection, global key accounting,
protected rewrapping, retention expiry and outside-transaction sink cleanup with exact-state acknowledgement.
The full local gate passes 1047 tests / 8980 assertions and exact 6245/6245 statements. Independent review accepted
`9a0a391` with all nine criteria passing, no findings, 526 fresh focused tests / 5057 assertions and all 718 gate/bridge
inputs verified. TASK-00053 is done for implementation/local verification. John's landing request published
[PR #87](https://github.com/johnnickell/fight-access-control/pull/87) against `develop` at initial publication head
`4b79cee`; the fresh landing gate retains 1047 tests / 8980 assertions and exact 6245/6245 statements. The ignored
landing handoff owns final PR-metadata verification and remote identity. No consumer qualification, merge or release
is claimed. Other slices retain their remaining dependencies and require separate
execution authority.
Each downstream execution needs authorization. John separately authorized TASK-00046
execution and PR landing. Planning does not qualify a consumer or authorize migration, release or adoption.
The replacement requires caller-scoped operation correlation, protected delivery and consumer authorization
participating in the package-owned transaction under shared authority fences. Consumers still own policy, adapters,
keys and the sink; unsupported integrations fail closed.

TASK-00047 adds [safe operation queries](docs/agent-operation-status.md) using the original key and destination,
`AgentOperationRepository::getStatusByKey()` and `AgentOperationAuthorization::authorizeRead()`. Consumers implement
current read authority independently of transaction-only issuance authorization; the second check includes the
original Agent target. Safe views retain persisted version and separate delivery/credential dispositions, including
tombstones. Unknown versions deny without reinterpreting requests. Storage failures are sanitized unavailable;
authoritative absence is indeterminate. Tests exercise real provision-to-read recovery and controlled persisted
fixtures for later dispositions, not actual downstream lifecycle/delivery writers or consumer database proof.

TASK-00046 implements provision and same-key service resolution, not the entire replacement. The old provisioning
signature and raw result are removed; its new safe result distinguishes confirmed issuance, publication warning and
indeterminate commit. Domain operation storage retains request version, safe issuance and separate protected material;
Application coordinates current authorization, reservation and atomic writes. See the
[public provisioning contract](docs/agent-provisioning-operations.md) for concrete defaults and adapter obligations.
New Agents persist a recoverable-operation marker and reject legacy aggregate raw rotation, including after
Permission changes. [TASK-00050 retirement](docs/agent-credential-retirement.md) allows terminal revocation while
preserving that marker. `AgentRepository::replace()` validates exact lifecycle successors and atomically cancels the
original credential operation through same-connection `AgentOperationRepository::retireCredential()`. Retirement
removes delivery material, advances the operation state revision and preserves original correlation/delivery history;
no old claim/admission snapshot can restore it. Concrete replacement/cancellation implementations must annotate both
Agent arguments as sensitive; transaction implementations must also redact the callback argument, whose captured or
bound state can expose authentication material. Interface annotations alone are not inherited by implementations.
All authority and delivery writers must share those fences/epochs.
TASK-00050's original evidence is controlled expected-state package proof, not a delivery worker or real consumer
qualification. TASK-00051 now adds the [protected delivery service](docs/agent-credential-delivery.md) using those
actual retirement fences: committed claim, separately committed admission with persisted authority/deadline,
sensitive fixed-slot invocation outside transactions, exact receipt verification and fresh fenced completion. The
operation stores immutable attempt/policy and receipt or closed failure evidence; completion removes only delivery
material. Defaults are a 60-second lease, 15-second admission, 30-second retry delay, 86400-second original retention
and 100 attempts, with validated bounded overrides pinned on first claim. Current scope/target/slot checks and
exact state revisions reject stale effects. Unconfirmed admission never decrypts; uncertain completion never guesses
success. Deterministic in-memory sink/authority and lifecycle tests do not qualify consumer databases or activation/use.
TASK-00048 adds `AgentCredentialRotationService`, `AgentRotationRequest` and mandatory transactional
`authorizeRotation()` scope/target/destination authorization. The old lifecycle `rotate()` explicitly rejects;
its result has no raw-secret getter. `AgentCredentialLifecycleService` drops the unused generator/cipher constructor
arguments while revocation behavior remains unchanged. Legacy Agents retain authentication/revocation and aggregate
state compatibility but cannot enter recoverable rotation until TASK-00055's qualified migration path. This deliberate
fail-closed intermediate state is unreleased and not deployable until all recovery/maintenance work is accepted and
downstream conformance/cohort work lands.

For those two replacement operations only, confirmed issuance commits return safe operation metadata with a typed,
sanitized publication warning if post-commit publication fails. Pre-commit failure and indeterminate commit are
distinct outcomes. Same-key resolution survives both publishers failing; TASK-00052 now supplies authorized scheduler discovery
through the actual delivery path. TASK-00049 still owns the combined provision/rotation proof with both publishers
failing and caller termination; TASK-00046 alone did not establish that outcome. The complete replacement requires that
retry does not repeat issuance or its audit fact. Committed issuance is neither confirmed credential delivery,
enrollment activation nor permission to launch: each requires its own confirmed outcome and current authorization.
This exception does not change revocation, AuthenticationService or other CommandHandlers. Failure/restart behavior
tests and consumer conformance are required before acceptance of the relevant implementation/integration. Package
provisioning tests are not proof of delivery, real database concurrency, consumer qualification or runtime readiness.

Credential-operation limits have documented finite defaults and optional validated consumer overrides; routine
operation/recovery needs neither manual configuration nor an extra human approval step. Current authorization still
applies. Capacity exhaustion rejects or defers new work with a clear retryable outcome while preserving safe status
lookup and recovery of existing operations. Cleanup retains the evidence needed to prevent duplicate issuance and
stale delivery. Provisioning defaults are 512 raw name bytes, 100 pending operations per scope and 10000 globally,
with validated bounded overrides; existing resolution precedes new-work admission. TASK-00052 adds a default batch of 50 (valid 1–100) and 30-second polling guidance (valid 1–3600 seconds),
with at most one batch per pass and no capacity check on existing recovery. Selection excludes obsolete reservations
using the stable slot's committed counter across all scopes/bindings, before ordering or limiting; no manual cleanup
is needed to reach the current write. Delivery policy remains pinned on
first claim; pending/retry/expired-claim due selection respects both lease and retry, capped by original retention.
TASK-00053 uses maintenance batches of 50 (valid 1–100), exclusive delivery-ID cursors, and a default inert-entry
cleanup grace of 86400 seconds (valid 1–604800) after original delivery retention. Current delivered authority is never
cleanup-eligible. Rewrap preserves the pinned delivery policy, or pins the existing default before first claim;
expiry and cleanup cannot extend original delivery retention. Reference counts include every retained copy across
scopes/batches/versions; physical retirement also requires closed write admission, fenced recount and independent
key-use/authentication-envelope accounting. This is not a wider quota, key-vault or approval system.

## Planning and Completion

Local TASK files under `planning/tasks/` are canonical for implementation scope, status, dependencies,
acceptance, and evidence. The Board ranks ready work. A TASK is executable only under the rules in
`planning/agents/issue-tracker.md`. Run `./bin/planning-check` for planning integrity and `./bin/build` for the
complete package gate.
