# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- [Self-service Agent profile Tools](docs/agent-profile-tools.md): additive `GetAgentProfileTool` and
  `UpdateAgentProfileTool`, with distinct managed read/update Permission requirements and principal-only target and
  initiator. Exact `{agent_id, name}` output uses Common's derived JSON text; the updater requires synchronous void
  dispatch and acknowledges normalized input for real changes/no-ops without a compensating read. Safe expected-failure
  bindings and targeted Common/package composition tests preserve concealment and post-commit uncertainty. No endpoint,
  consumer qualification, persistence/API replacement or release is supplied.
- [Agent-protected MCP authorization](docs/agent-mcp-authorization.md): additive repeatable method-level
  `RequiresAgentPermission`, immutable registry-derived `AgentToolPermissionCatalog` and request-scoped
  `AgentToolAvailability`. Conjunctive direct-Permission decisions share one current Agent snapshot across Common
  discovery/invocation and re-resolve for later protected retries. Missing/malformed composition rejects; unknown or
  foreign definitions and unresolved/insufficient authority deny without protected work. Real Common-boundary tests
  cover private zero-TTL pages, concealment and retry ordering. No Tool, endpoint or consumer qualification is supplied.
- [Agent name updates](docs/agent-name-updates.md): typed User/Agent `AgentUpdateInitiator`, generic `UpdateAgent`,
  void synchronous handler and post-commit `AgentNameChanged`. The new mandatory `AgentRepository::rename(id, name, now)`
  samples its clock callback after current ACTIVE/operation/cohort admission and returns exact persisted time or null
  for a validated no-op, avoiding false stale-time rejection after waiting for another writer. Last committed name
  wins without replacing credentials or Permissions; no-op has no write/timestamp/fact. Generated additive command
  schemas and focused package race/rollback/publication evidence accompany the contract. Consumers must update and
  qualify adapters and protect all entry points; no MCP Tool, administrator endpoint, legacy bridge or release.
  The required repository-interface addition belongs in the next pre-v1 minor release, not a patch/backport.
- [Human credential expiry cleanup](docs/credential-expiry.md): bounded secret-free
  `FindExpiredCredentialDeliveries`/`ExpiredCredentialDelivery`, direct `ExpireInvitationDelivery` and post-commit
  `InvitationDeliveryExpired`. Existing reset delivery expiry and full email authority/reservation expiry now cover
  downtime, reclaimed failure history, terminal email delivery and every reachable User state. Default-suite public-port
  recovery/race and generated-schema tests accompany the contracts; consumer PostgreSQL/adoption remains unqualified.
- **Unreleased v0.5.0 integration change:** all three human grant repositories must implement `findExpired(at, limit)`
  (1–100, eligibility before ordering/limiting). Email `replace()` covers exact next delivery or authority state and
  preserves the mandatory immutable User reservation-revision binding. `EmailChangeGrant::issue()` requires that
  positive binding as its seventh argument; persist/hydrate it and include it in complete-state CAS. Expiry serialization
  preserves fractional seconds and rejects inferred/relative or normalized-invalid timestamp payloads. Cleanup failure
  facts use safe constant text rather than arbitrary storage diagnostics while rethrowing the original fault.
  Additive query/result/command/fact schemas and these current-contract changes belong
  in the next minor pre-v1 release, not a patch/backport or a compatibility/migration bridge. Release remains separate.
- [Guarded Feature retirement](docs/feature-retirement.md): `RemoveFeature` and post-commit `FeatureRemoved`,
  complete CURRENT-scope discovery of native and explicit references, and mandatory full-expected-state
  `FeatureRepository::remove()` under the shared transaction/reference fence. Later provisioning allocates a new
  identity OFF/1 with the current default; old requests cannot retarget it. Consumer-bindable retirement scenarios
  and a [complete Feature evidence inventory](docs/feature-evidence.md) distinguish package proof from real
  scanner/database/UI/runtime qualification. No deployment fencing, name tombstone, transport API or release.
- [Feature management](docs/feature-management.md): additive manual OFF creation, safe read/list views, atomic
  revision-checked status and testing-Permission edits, and post-commit facts. The Feature repository now requires
  paginated reads and a reference-fenced compare-and-replace operation; consumers must update persistence adapters
  and schema before adoption. No UI, transport API, consumer migration or partial release; guarded retirement
  is described separately above.
- [Feature availability](docs/feature-availability.md): additive read-only evaluator for fresh stored status and
  bound Permission identity against existing User/Agent snapshots or anonymous input. OFF/PREVIEW/ON return ordinary
  booleans; unknown Features, broken bindings and operational failures remain distinct. No action authorization,
  consumer adapter/enforcement or partial Feature release is supplied; management is described separately above.
- [Atomic Feature provisioning](docs/feature-provisioning.md): additive Feature identity/status/model and repository
  contracts, `ProvisionFeatures`/handler and post-commit `FeatureCreated` facts. Complete candidate discovery creates
  only missing names OFF in one transaction with an existing default Permission; rollback, uniqueness conflicts,
  uncertain commits and publication failure preserve storage-based retry and existing choices. Additive opt-in
  `Fight.AccessControl.ProvisionFeatures` schema and generated-contract tests. No activation-readiness claim,
  consumer adapter, reliable event delivery or selected release version; evaluation and management are separate slices.
- Portable [Feature declarations and discovery](docs/feature-references.md): strict non-normalizing `FeatureName`,
  method-only nonrepeatable `FeatureFlag`, immutable name references and fail-closed candidate/current discovery
  results. Additive PHP metadata contracts only; no storage, provisioning, evaluation, scanner or runtime enforcement.

- [Current Agent integration guidance](docs/agent-integration.md) and the
  [complete scenario/evidence inventory](docs/agent-operation-evidence.md): all 25 proposal scenarios plus ratified
  outcome/bounds additions, current package test provenance, superseded legacy/upgrade obligations and explicit
  real-adapter/sink/authority/restore/activation gaps. Documentation only; no consumer qualification or release receipt.
- One unreleased [Agent operation canonical contract](docs/agent-canonical-upgrades.md) with fixed Unicode
  edge-whitespace normalization and marker `2`. Before first adoption, removed the speculative v1 reader,
  creation-version/reader-set selection and cross-version fixtures; request `canonicalize()` methods take no version
  argument. `AgentOperationContract` validates one `canonicalVersion` instead of creation/reader settings. Retry,
  status, lifecycle and cleanup retain original keys and issuance; unsupported markers fail closed. Consumer-bindable
  restart/safety scenarios accompany the behavior. The confirmed-operation OpenAPI schema permits only marker `2`.
  No backward-compatible shim, historical migration or runtime version choice is supplied.
- Explicit validated `Agent::reconstitute()` for persisted authority, without credential generation or fabricated
  correlation. Current active authentication and terminal revocation are preserved. See the
  [current Agent contract](docs/agent-current-contract.md).
- Authorized Agent delivery material rewrapping, retention expiry and replay-safe inert sink cleanup through
  `AgentDeliveryMaintenanceService`. Bounded `ListAgentDeliveryMaintenance` and global diagnostic
  `CountAgentDeliveryKeyReferences` queries are read-only; zero references never authorize physical key retirement.
  Opt-in schemas mirror the safe queries/results. See the [unreleased maintenance contract](docs/agent-delivery-maintenance.md).
- Reusable OpenAPI `DeliverPasswordReset`, `FindCredentialDeliveryStatus`, `FindDueCredentialDeliveries`, safe
  `CredentialDeliveryStatus` and `DueCredentialDelivery` components, plus an unpaginated due-work list and optional
  typed success envelopes. Generated delivery-contract and composition checks run in default PHPUnit, CI and build;
  release qualification reuses them. Runtime delivery and consumer authorization are unchanged.
- Bounded authorized `ListDueAgentDeliveries` queries and `AgentDeliveryRecoveryService` scheduler passes through
  the actual protected delivery path. Optional `AgentCredentialReceiptLookup` reconciles exact durable receipts
  before unnecessary materialization. Restart/uncertainty recovery preserves original issuance and secret identity.
  Due selection excludes obsolete slot reservations before limiting, preventing rejected predecessors from starving
  the current authorized delivery across scheduler restarts.
  See the [unreleased recovery contract](docs/agent-delivery-recovery.md).
- Opt-in OpenAPI discovery Query, shared safe Agent operation/issuance, bounded due-list and optional JSend schemas,
  with generated-contract integration coverage in the default PHPUnit/build pipeline.

- Authorized secret-free `GetAgentOperation` status queries with canonical array round trips, original issuance
  separated from recorded delivery/credential disposition, retained-key reads and honest indeterminate outcomes.
  See the [unreleased status contract](docs/agent-operation-status.md); status never grants secret access or launch.

### Changed

- **Dependency compatibility (unreleased):** raise `johnnickell/fight-common` from `^1.2` to `^1.3` for the released
  MCP Tool metadata, registry, neutral availability and protected-interaction contracts. This narrowed minimum belongs
  in the next pre-v1 minor release, not a patch/backport. Existing non-MCP AccessControl APIs remain unchanged.
- **Breaking (unreleased v0.5.0):** `AgentOperationContract` requires explicit nullable `reconciledGeneration` from
  an independently trusted admission boundary for the exact active storage incarnation. Missing or mismatched evidence
  denies unsafe operations through existing cohort guards; a reconciled restore advances the generation so old delivery
  and cleanup acknowledgements remain invalid. Consumer-bindable restored-state scenarios retain independent sink
  receipts/tombstones/order. See [restoration safety](docs/agent-restoration-safety.md); no real consumer backup/restore,
  migration engine, automatic rollback detection or activation/use qualification is supplied.
- **Breaking (unreleased v0.5.0):** Agent and operation repositories now require `getOperationContract()` with
  persisted versions, monotonic cohort generation, qualified local capabilities and a shared transaction-duration
  fence. Issuance, lifecycle, direct Agent Permission changes, discovery, delivery and maintenance reject incompatible
  composition. Delivery and cleanup authority epochs are cohort-bound; old admissions cannot acknowledge across a
  switch. Consumers must fence old binaries externally and qualify actual writers; no deployment proof is inferred.
  See the [cohort contract and switch guide](docs/agent-operation-cohorts.md).
- **Breaking (unreleased):** Only the current pre-v1 contract is supported. Remove the legacy Agent mode, recovery
  marker/getter/View/schema field, adoption transition, raw aggregate rotation and lifecycle rejection-only `rotate()`.
  All lifecycle writes require current operation correlation and atomic cancellation. Hydration, authentication,
  authority fences, uncertain-commit/restart resolution and retained-key/order/receipt safety remain.
- **Breaking (unreleased):** User delivery factories require explicit due time; claim and outcome methods require
  explicit token, occurrence/lease times and retry failure classification. Remove inferred transitions, purpose-specific
  `isRecoverable()` aliases and the obsolete `ConfirmPasswordResetDelivery` command/handler/OpenAPI component.
  Workers retain `PasswordResetDeliveryConfirmed` after a live-claim outcome commits. Historical migration guidance is
  retired; no old-data conversion or data reset is supplied.
- **Breaking (unreleased):** `ManagedPolicyPlanner` requires an `AgentRepository`; remove the optional older
  constructor wiring and null-repository promotion branch. Authoritative protected-promotion membership checks remain.
- **Unreleased v0.5.0 integration change:** operation adapters add `listMaintenance()`,
  `countDeliveryKeyReferences()` and exact-state `replaceMaintenance()`, persist `sinkCleaned`, and share key-version
  write/reference fences across all writers. Consumers implement maintenance authorization, bound ciphertext
  rewrapping and exact-tuple cleanup retaining permanent deduplication/order evidence. No production adapter or
  physical key-destruction capability is supplied; downstream consumer qualification remains required.
- **Breaking schema correction (unreleased):** `InvitationDeliveryStatus.status` now matches the seven released
  credential-delivery states, adding `retry_pending`, `delivered`, `permanent_failure`, and `invalidated` while
  removing obsolete `failed` and `confirmed`. Its existing success-envelope reference is preserved. Enum narrowing
  requires the next minor `0.x` release under ADR 0007; this does not select a version or replace published v0.4.0.
  See [composition guidance](docs/openapi-composition.md).
- **Unreleased v0.5.0 integration change:** repositories must implement read-only `listDueDeliveries()` and delivery
  authorization must implement `authorizeDiscovery()` as the real delegated worker. Live recovered admissions allow
  receipt reconciliation only; another invocation requires fresh confirmed admission. Recorded delivery with a lost
  sink entry/receipt returns `reconciliation_required` without rematerialization or automatic rotation.

- **Breaking (unreleased v0.5.0):** `AgentCredentialRotationService` requires a retained scoped key, original target
  and predecessor ID/revision, registered destination and transactional `authorizeRotation()` authority. It commits
  one successor, pending protected delivery and audit with atomic predecessor cancellation. Retries resolve the
  original outcome; uncertain commits remain indeterminate and publication failures return typed safe warnings.
  `AgentCredentialRotationResult` exposes only safe metadata; the old lifecycle `rotate()` is removed.
  The revocation service drops obsolete generator/cipher constructor arguments but keeps its existing failure behavior.
  See the [rotation contract](docs/agent-rotation-operations.md). Package restoration is implemented;
  real consumer qualification remains separate.

- **Unreleased v0.5.0 integration change:** operation repository adapters must implement `getStatusByKey()` and
  persist delivery/credential dispositions; authorization implementations must add side-effect-free `authorizeRead()`
  with current invoker/delegation, destination and target checks. No permissive fallback or consumer adapter is supplied.

- **Breaking (pre-1.0, unreleased v0.5.0):** Agent provisioning now requires a retained scoped operation
  key and registered destination, with transactional consumer authorization. It atomically prepares encrypted delivery
  and returns safe issuance metadata, a distinct indeterminate outcome, or a typed publication warning after confirmed
  commit. Same-key retry does not issue again. The old raw-return provision signature is removed. See the
  [provisioning contract](docs/agent-provisioning-operations.md). Recoverable Agents require operation-aware rotation
  and atomic retirement. The current protocol includes package restoration guards, but does not establish release
  or real consumer qualification; see the integration/evidence guides above.

## [0.4.0] - 2026-09-27

### Changed

- **Breaking (pre-1.0):** Every Permission now has a non-null tier. Custom Permissions are always `ADMIN_SAFE`,
  and Permission read results serialize their tier. Consumers must update persistence mappings, hydrators, and
  projections for the non-null contract. See the [v0.4.0 migration guide](docs/permission-tier-v0.4-migration.md).
- **Breaking (pre-1.0):** Custom-Role grant/revoke handlers no longer accept the actor-only
  `RoleAdministrationAuthorization` port. Granting requires an authoritative `ADMIN_SAFE` Permission even on a
  no-op; Role repository adapters must fence Permission tier authority through custom-Role writes. See the
  [v0.4.0 migration guide](docs/permission-tier-v0.4-migration.md).
- **Breaking (pre-1.0):** Custom-Role create/rename/remove and User Role assign/remove handlers no longer accept
  actor-only authorization ports. The application builder must protect every entry point, including no-ops; command
  actor IDs remain provenance, not credentials. `ROLE_SUPER_ADMIN` is reserved for a uniquely authoritative managed
  Role; pending Users may receive it for bootstrap under ordinary assignment rules. See the
  [v0.4.0 migration guide](docs/permission-tier-v0.4-migration.md).
- **Breaking (pre-1.0):** Direct Agent grant/revoke/replace handlers no longer accept the actor-only
  `AgentPermissionAdministrationAuthorization` port. Grants and complete-set replacements require authoritative
  `ADMIN_SAFE` Permissions even on no-ops; Agent repository adapters must fence tier authority through assignment
  writes. Consumers protect every entry point. See the [v0.4.0 migration guide](docs/permission-tier-v0.4-migration.md).
- **Breaking (pre-1.0):** Managed policy permits protected Permissions only on authoritative managed
  `ROLE_SUPER_ADMIN`. Preview and reconciliation reject promotion while any custom Role, ordinary managed Role, or
  Agent holds the Permission, without automatically removing membership. Consumer repositories must share atomic
  grant/promotion fences, provide `AgentRepository::hasPermissionAssignment()`, and inject the Agent repository into
  `ManagedPolicyPlanner` for promotion. See the [v0.4.0 migration guide](docs/permission-tier-v0.4-migration.md).

## [0.3.0] - 2026-09-25

### Added

- Recoverable package-owned delivery state, deterministic due-work discovery, safe status queries, direct delivery
  handlers, and one provider-neutral typed outcome contract for invitation, password reset, and email change.

### Changed

- **Breaking (pre-1.0):** `InvitationDeliveryInvoker` and `EmailChangeDeliveryInvoker` are replaced by one
  `CredentialDeliveryProvider`; purpose-specific ciphers now decrypt claimed `EncryptedCredentialMaterial`, and grant
  repositories must persist complete claim/outcome state and implement deterministic due-work discovery on the shared
  transactional connection. See the [v0.3.0 migration guide](docs/credential-delivery-v0.3-migration.md).
- **Breaking (pre-1.0):** Transaction-aware public constructors now require Fight Common's supported
  `TransactionalUnitOfWork` contract instead of the deprecated `UnitOfWork` contract. Consumers must update their
  composition bindings; AccessControl does not provide a compatibility bridge or require the legacy standalone
  `commit()` method.

## [0.2.0] - 2026-09-13

### Added

- OpenAPI component metadata for consumer-owned `v0.2.0` documents.

## [0.1.0] - 2026-09-10

### Added

- Framework-neutral User invitation, activation, authentication, refresh-session, logout, password-reset,
  password-change, email-change, account-state, and account-recovery behavior.
- Role and Permission definition, assignment, reconciliation, administrative reads, immutable authenticated User
  authority, and retry-safe desired-state authorization changes.
- Agent provisioning, credential rotation and revocation, direct Permission authority, HMAC request
  authentication, replay protection, immutable authenticated Agent authority, and secret-free diagnostics.
- One final request-scoped `SecurityContext` over distinct User or Agent principals with shared immutable
  Permission snapshots and package-owned Role and Permission checks.
- Framework-neutral behavioral conformance support, package-boundary enforcement, deterministic repository
  tooling, and exact production-statement coverage.
- Repository-local product, security, architecture, contribution, and Git Flow authority under the MIT License.

[Unreleased]: https://github.com/johnnickell/fight-access-control/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/johnnickell/fight-access-control/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/johnnickell/fight-access-control/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/johnnickell/fight-access-control/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/johnnickell/fight-access-control/releases/tag/v0.1.0
