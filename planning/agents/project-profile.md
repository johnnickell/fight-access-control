# Fight AccessControl Project Profile

This package binds the [Fight Engineering Standards](../../docs/engineering/STANDARDS.md) to its Domain and
Application package boundary.

## Pre-v1 compatibility policy

Follow [ADR 0011](../adr/0011-pre-v1-current-contract-only.md): implement only the current API and persisted contract.
Do not add or retain code solely to support previous iterations, including aliases, rejection-only retired methods,
legacy modes, historical readers, compatibility defaults or migration/backfill paths. Update callers/tests directly.
Keep current-contract authorization, atomicity, retry/restart, hydration, ordering and restoration safety. Release
classification/changelog rules remain; they do not require compatibility code. TASK-00068 owns removal still pending
in existing source; completed TASKs and earlier guidance cannot require that code to remain.

## Package boundary and contracts

- Production code is limited to src/Domain/AccessControl and src/Application/AccessControl, mirrored by tests.
- Consumers own production persistence adapters, transport, framework composition, keys, queues, and runtime. Do not
  add a production Adapter layer, contracts directory, or Composer production namespace without explicit approval.
- Reuse public Fight Common values and identities: context IDs extend UniqueId, email is the common EmailAddress,
  messages use the public Command, Query, Event, and handler contracts, and package exceptions stay under their
  aggregate Exception namespace.
- Repositories are Domain interfaces beside their aggregate. Handlers use canonical add/getById/remove operations and
  the supported atomic boundary; do not create Application Store ports, save ports, or preflight reservation calls.
- UserRepository::add() enforces canonical-email uniqueness atomically. Backed enum cases are uppercase and retain
  stable serialized string values.

## Application and security behavior

- Secret-bearing activation, login, refresh, logout, and password operations remain synchronous
  AuthenticationService methods. Raw secrets are sensitive parameters, never serializable messages, and failures
  dispatch only redacted failure evidence.
- Serializable Commands, Queries, and Events are immutable DTOs with canonical fromArray/toArray round trips,
  named getters, and rejection of missing required data.
- Command and Query handlers implement the corresponding public Fight Common handler interface, declare their
  registration method, and extract the typed payload from the received message.
- A CommandHandler owns one atomic Unit of Work: aggregate mutation and repository persistence precede one commit;
  a success event is dispatched only after that commit. On failure it dispatches CommandFailedEvent with the original
  command and message, then rethrows the same throwable.
- Approved replacement exception: [EPIC-00009 D3](../epics/00009-EPIC.md#d3--post-commit-result-behavior), ratified by
  John on 2026-09-27, applies only to replacement recoverable Agent provision and rotation. After a confirmed commit,
  publication failure returns committed metadata with a typed, sanitized warning, not a publication-failure throw.
  Pre-commit failure and indeterminate commit remain distinct; same-key resolution and authorized scheduler discovery
  survive even both publishers failing. This prevents a notification fault disguising committed issuance. Issuance
  confirms neither delivery, enrollment activation nor launch permission; each needs its own confirmed outcome and
  current authorization. Revocation, AuthenticationService and other handlers retain their publication behavior.
  TASK-00046 implements the provision exception and TASK-00048 implements rotation as unreleased work; the old
  raw-return rotation API now rejects. Scheduler proof remains downstream. This guidance grants no independent implementation or release authority.
- For that credential-operation replacement, follow ratified [EPIC-00009 D4](../epics/00009-EPIC.md#d4--bounded-operation-and-integration-policy):
  documented finite defaults, optional validated overrides, and no manual-configuration or additional human-approval
  requirement for routine operation/recovery. Capacity exhaustion must give new work a clear retryable rejection or
  deferral while preserving authorized status and existing-operation recovery. Cleanup must preserve duplicate-issuance
  and stale-delivery defenses. Specify concrete values and implementation choices in requirement/design work and prove
  default/override, capacity/recovery and cleanup/replay behavior before implementation acceptance.
- Agent/operation repositories implement mandatory `getOperationContract()` against a shared persisted cohort and
  qualified actual composition. Every writer holds its cohort fence through the package transaction; direct paths and
  legacy markers cannot bypass it. Delivery/cleanup epochs bind its monotonic generation. Startup checks are not
  admission; consumers separately fence old binaries and qualify real writer races. See the
  [cohort contract](../../docs/agent-operation-cohorts.md). This is unreleased TASK-00056 work, not consumer qualification.
- Operation requests use one fixed Unicode-edge name rule and persisted canonical marker `2`. John's 2026-09-30
  pre-adoption amendment removes historical readers, creation/reader-set selection and backward-compatibility shims.
  Request `canonicalize()` methods take no version argument. Retained keys/tombstones keep original request/issuance
  through cleanup; unsupported markers fail closed without migration or fallback. Storage/destination versions stay 1;
  repositories validate the one supported contract and share its generation/transaction fence. Safe reads do not
  grant writer admission. See the [canonical contract](../../docs/agent-canonical-upgrades.md). Actual persistence,
  authority/sink fencing and restoration qualification remain separate from package proof.
- TASK-00055's legacy-Agent mode and upgrade contract are superseded by ADR 0011; TASK-00068 removes them, their
  recovery marker and old-API stubs. Current-contract hydration still validates persisted authority, and every current
  lifecycle write must retain operation correlation/cancellation and audit atomicity. No prior-data conversion,
  legacy adoption or schema migration is a package obligation.
- QueryHandlers read through Domain repositories only: no aggregate mutation, commit, or domain-event dispatch.
- AuthenticationService follows the same atomic, post-commit ordering, uses Fight Common password and token ports,
  returns non-serializable token results, and emits RedactedCommandFailed without raw secret input.
- Aggregate roots own state transitions. Keep aggregate entities extensible; use final readonly only for immutable
  DTOs, handlers, and services where extension is not part of the contract.

## Tests and delivery

- In-memory repositories and service doubles belong in the matching Application test boundary. Production tests use
  CoversClass; tooling tests use CoversNothing. Handler tests prove post-commit event ordering and failure rethrow
  behavior outside the scoped EPIC-00009 D3 replacement exception. Acceptance of that replacement requires tests for
  pre-commit rollback, both uncertain-commit outcomes, post-commit publication failures including both publishers
  failing, same-key recovery after response loss/restart, and authorized scheduler-only recovery without caller retry.
  Assert persisted outcomes and typed warning safety, not only call order; issuance cannot imply delivery, activation
  or launch authority. Real consumer conformance must separately prove current activation/use authorization.
- Shipped OpenAPI metadata is a public library contract. Its generated-schema integration tests belong in the
  default PHPUnit/build pipeline under the approved
  [OpenAPI testing rule](../../docs/engineering/STANDARDS.md#accesscontrol-openapi-contract-integration-tests),
  not the release-only tooling category. TASK-00052 established suite wiring and Agent discovery checks;
  TASK-00060 adds released credential-delivery and shared composition checks. Exact Domain/Application statement
  coverage remains unchanged.
- Application clocks, credential generators, and ciphers belong under the matching Application aggregate Service
  namespace; their test doubles use the matching test Service namespace.
- Every production statement requires executable coverage. The isolated fight-access-control PHP container is the
  package runtime; ./bin/planning-check and ./bin/build are mandatory pre-submit gates, and the build enforces
  PHPCS, PHPStan, architecture, Rector, PHPUnit, and exact statement coverage.
- Keep ignored run artifacts in purpose-named subdirectories: `.runs/worktree/<task-slug>/` for linked worktrees,
  `.runs/notes/<task>/` for coordination, `.runs/logs/<task>/` for gate logs and receipts, and
  `.runs/handoffs/<task>/` for reusable handoffs. Canonical reviews remain `.runs/reviews/<TASK-ID>/review.md`.
  Do not create mixed-purpose dated folders directly under `.runs/`. See the approved
  [run-layout deviation](../../docs/engineering/STANDARDS.md#accesscontrol-run-layout).
  Retain worktrees and evidence through review. Branch feature work from develop and never commit directly to
  develop or main. Release certification, tags, publication, and cleanup remain separately authorized.
