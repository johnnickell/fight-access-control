# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Explicit validated `Agent::reconstitute()` for persisted authority, without credential generation or fabricated
  historical correlation. Existing active authentication and terminal revocation are preserved. See the
  [unreleased existing-data/API migration contract](docs/agent-existing-data-v0.5-migration.md).
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

- **Breaking (unreleased v0.5.0):** Agent and operation repositories now require `getOperationContract()` with
  persisted versions, monotonic cohort generation, qualified local capabilities and a shared transaction-duration
  fence. Issuance, lifecycle, direct Agent Permission changes, discovery, delivery and maintenance reject incompatible
  composition. Delivery and cleanup authority epochs are cohort-bound; old admissions cannot acknowledge across a
  switch. Consumers must fence old binaries externally and qualify actual writers; no deployment proof is inferred.
  See the [cohort contract and switch guide](docs/agent-operation-cohorts.md).
- **Breaking (unreleased v0.5.0):** `AgentView` and the shipped Agent OpenAPI component require the safe
  `recoverable_credential_operation` boolean; direct View constructors require the final marker argument. Known active
  legacy Agents may enter recovery through the existing explicitly authorized rotation service. Repositories must
  atomically persist that marker transition with only the new successor's operation/delivery/audit, never invent
  predecessor history or downgrade correlated credentials. Upgrade alone performs no rotation or redelivery.
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
  `AgentCredentialRotationResult` no longer exposes raw material, and the old lifecycle `rotate()` explicitly rejects.
  The revocation service drops obsolete generator/cipher constructor arguments but keeps its existing failure behavior.
  See the [rotation contract](docs/agent-rotation-operations.md). Migration and complete recovery remain downstream.

- **Unreleased v0.5.0 integration change:** operation repository adapters must implement `getStatusByKey()` and
  persist delivery/credential dispositions; authorization implementations must add side-effect-free `authorizeRead()`
  with current invoker/delegation, destination and target checks. No permissive fallback or consumer adapter is supplied.

- **Breaking (pre-1.0, incomplete v0.5.0 composition):** Agent provisioning now requires a retained scoped operation
  key and registered destination, with transactional consumer authorization. It atomically prepares encrypted delivery
  and returns safe issuance metadata, a distinct indeterminate outcome, or a typed publication warning after confirmed
  commit. Same-key retry does not issue again. The old raw-return provision signature is removed. See the
  [provisioning contract](docs/agent-provisioning-operations.md). Recoverable Agents require operation-aware rotation
  and atomic retirement; do not deploy this partial protocol before downstream recovery and compatibility qualification.

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
