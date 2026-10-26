# Fight AccessControl Project Profile

This package binds the [Fight Engineering Standards](../../docs/engineering/STANDARDS.md) to its Domain/Application
core and the approved optional, capability-first Adapter integration boundary below.

## Agent OS workflow binding

Fight Agent OS skills and its custom Pi harness are optional contributor tooling. This checkout remains
self-contained: without them, follow [AGENTS](../../AGENTS.md), [planning conventions](../CONVENTIONS.md) and the
local standards directly, retaining the same implementation, independent review, behavioral QA and delivery boundaries.

### Entry and authority

Use `/skill:next` for read-only navigation and `/skill:build TASK-NNNNN` for approved implementation or confirmed
repair. Substitute the actual selected TASK ID. An explicit implementation-only request uses `/skill:work TASK-NNNNN`;
other continuations follow [the stage table](../CONVENTIONS.md#workflow-continuations).

A recommendation or planning acceptance does not invoke Build. Explicit invocation delegates the provider-defined
Engineer → independent Review → independent QA → land sequence, including TASK-owned commits, non-force push,
PR creation/update and suitable sanitized evidence publication. Do not request approval again for already delegated
effects. Each child performs only its assigned stage; the coordinator continues within the original scope.
Build never authorizes PR approval/merge, force push, signed release, deployment, another TASK, unrelated changes
or restricted disclosure. Ordinary edits do not imply publication authority. A real missing decision, prerequisite,
ambiguous ownership or host restriction holds the affected action with its exact recovery input, not a generic
approval loop. Keep [signed release controls](../../docs/engineering/standards/Delivery.md#signed-library-releases).

Reuse the operator's existing TASK checkout choice. Otherwise resolve main checkout versus isolated worktree
before implementation. Verify Git root, remote, common directory, registered worktrees, branch/base and status;
use `feature/task-NNNNN-<slug>` from `develop`, preserving an authorized existing TASK branch and unrelated work.
Correction-batch implementation/review still requires its full approved maintainer brief through the established
private channel. Absence from public records proves neither availability nor unavailability: verify the authorized
private handoff, and name the missing input if actually unavailable. General workflow maintenance does not require
that brief. Never copy restricted criteria into tracked docs, logs intended for publication or PR prose.

### Provider resolution and target substitutions

Resolve the owning skill from the resource supplied by the harness/operator, then resolve its nested references
relative to that provider. Shared resources such as `docs/engineering/PROJECT_CONTEXT.md`, `EXECUTION.md`,
`HANDOFFS.md`, `QA.md` and `LANDING.md` remain provider-owned; their paths are provider-relative, not files this
package must copy or recreate. Do not guess an installation path, copy the skill catalog, modify the provider,
install/enroll tooling or change global settings as routine intake.

Build and Next are explicit-only entrypoints (`disable-model-invocation: true` in the inspected provider).
Pi omits such skills from automatic model-selection listings; omission is not proof of missing capability.
Confirm the supplied skill resource and explicit command support. If a required resource, authentication, trust
or tool is genuinely missing, record that setup prerequisite separately and obtain only the needed decision.
Inspection, enrollment or an available command is not end-to-end Build qualification. Native file/shell tools
remain ordinary Pi tools, not OS isolation or universal Jev enforcement. Optional discovery does not grant authority.

Pi loads context from its working directory and ancestors, not every descendant instruction file when reading
source. Explicitly read this profile, applicable CONTEXT sections and linked standards; do not assume they were
automatically loaded. Before every delegated stage, the coordinator supplies a private reusable project-context
path plus owning skill, target checkout, TASK/base/head, permitted effects and evidence paths. The child verifies
Git identity and reads that context and target instructions before the skill; apply these substitutions through
all nested references:

| Concern | AccessControl authority |
| --- | --- |
| Instructions and accepted scope | Root `AGENTS.md`, this profile, `CONTEXT.md`, selected `planning/tasks/NNNNN-TASK.md` and applicable accepted parents |
| Planning/navigation | `planning/CONVENTIONS.md`, `planning/tasks/BOARD.md`, `planning/wayfinder/README.md`, authored `planning/ROADMAP.md` handoff; generated views remain generator-owned |
| Engineering rules | Local `docs/engineering/STANDARDS.md` and its selected standards; preserve package exceptions below |
| Runtime and commands | Isolated package PHP Docker runtime through `./bin/*`; no application webserver, database, HTTP Adapter or UI provisioning required for package-only work |
| Gates | `./bin/planning-check --write` after record changes, then `./bin/planning-check`, `./bin/documentation-check`, `git diff --check`; canonical `./bin/build` under local Testing |
| Full-gate evidence reuse | Only [Testing's documentation-only rule](../../docs/engineering/standards/Testing.md#reusing-a-full-gate-result-after-documentation-only-follow-ups), with complete earlier log/exit and proven input equivalence; never call retained evidence a fresh pass |
| Hosted checks | Existing [Testing policy](../../docs/engineering/standards/Testing.md#gate-boundaries): local proof is mandatory; builders/internal reviewers do not monitor hosted CI unless explicitly required/authorized. Public visibility or workflow files alone create no new gate. Land resolves actual declared requirements and host enforcement; unchecked is not passed |
| Completion | `done` requires independent acceptance and required verification, including applicable QA; it means neither publication, merge nor release. Apply automatic parent completion and regenerate views in the same authorized closeout |

Provider application planning, runtime, infrastructure, receipts and release examples are not AccessControl facts.
Preserve Domain/Application core ownership and the approved optional Adapter boundary, exact statement coverage,
OpenAPI integration coverage, pre-v1 current contracts and the security rules below. Unresolved conflicts hold only the affected action with its owner named.

### Integrated delivery and resource retirement

At authorized Work/Land/substantive planning intake run `./bin/planning-check --reconcile-delivery` before record
edits under [current delivery handoffs](../CONVENTIONS.md#current-delivery-handoffs). Inspect affected authored
Board/Roadmap/README/context claims separately; preserve historical checkpoints and independent reports.
Routing uses read-only `--check-delivery`; verified MERGED/integrated `done` resolves without routine cleanup
requests. Review/QA/routing never write. Exceptional/uncertain states retain their exact owner/input hold.

For TASK publication, resolve the live GitHub default against AccessControl's `develop` policy and existing
subject PR before reconciliation, pre-publication and final handoff. Use the resolved provider's
`.pi/skills/land/scripts/bind-target.py` with target `--root`, `--policy develop`, and existing `--pr`/`--head`.
Retain its observation and use the bound base throughout. Stale `origin/HEAD` is not authority; default/policy/PR
conflicts require explicit owner disposition, not silent retargeting.

Consume the provider's `docs/engineering/LANDING.md` cleanup-only, inventory/preservation, live-consumer,
Git/Docker and postcondition/resume contract (TASK-00534 plus qualified TASK-00537), alongside local gates.
Publication, document synchronization and eligible local retirement are separate required obligations; a hold
means incomplete cleanup. Verified-merge cleanup-only needs no correction PR or repeated acceptance gate.
Discover orphan stacks independently of worktrees, corroborating paths/source/topology/data rather than names.
After worker exit continue from a safe checkout with original model/ledger/authority, preserving active sessions.
Qualify Docker VM read-only sharing positively using all containers (including stopped), parent mounts, host
cwd/writers and stable preservation manifests; arbitrary/unknown readers hold, never shut down a shared VM.
Preserve active/dirty/shared resources, independent reports, credentials and durable data. Reobserve consumers,
resume only missing effects and verify exact postconditions with finite recovery actions.

### Evidence and resume

Resolve the canonical base checkout through Git common-directory and registered-worktree metadata, rather than
assuming the current directory owns canonical evidence. Use these ignored target locations:

- `<base>/.runs/build/<TASK-ID>/` — coordinator context, ledger, locks and per-stage attempts.
- `<base>/.runs/reviews/<TASK-ID>/review.md` — canonical independent technical report under local Review.
- `<base>/.runs/qa/<TASK-ID>/qa.md` — canonical independent behavioral report under the provider QA contract.
- `.runs/tasks/<TASK-ID>/runs/` — owned execution scratch; `.runs/logs/<TASK-ID>/` and
  `.runs/handoffs/<TASK-ID>/` — complete gate logs/receipts and reusable handoffs.
- `.runs/worktree/<task-slug>/` — optional linked checkout; retain useful evidence and owned runtime through review.

Match repository, TASK/requirements, branch, full base/head and working-tree snapshot before advancing. Review and
QA must be independent of implementation and its submitted acceptance evidence; green commands or child exit zero
are not verdicts. Use local Review's current v3 report or identity-compatible v2; QA records scenarios, disposition,
limitations and subject under its owning contract. Executable routing/instructions need behavioral QA even without
UI; screenshots may be unhelpful, but overall QA is not automatically N/A.

Preserve canonical report history, stage attempts, model pins and the original Build ledger on resume. Default
Build has five shared repair cycles across Review and QA (an explicit provider-supported allowance may differ);
initial implementation is not a repair, QA FAIL never resets usage and exhaustion requires human continuation.
INCOMPLETE evidence needs prerequisite recovery, not speculative source edits. Stale or contradictory subjects
need reconciliation/reassessment, not selection of an older PASS. Administrative-only changes may bridge accepted
content only under the provider landing contract with proven equivalence; material changes need fresh applicable
independent evidence. Publish only sanitized evidence and never treat a local path as published PR proof.

## Pre-v1 compatibility policy

Follow [ADR 0011](../adr/0011-pre-v1-current-contract-only.md): implement only the current API and persisted contract.
Do not add or retain code solely to support previous iterations, including aliases, rejection-only retired methods,
legacy modes, historical readers, compatibility defaults or migration/backfill paths. Update callers/tests directly.
Keep current-contract authorization, atomicity, retry/restart, hydration, ordering and restoration safety. Release
classification/changelog rules remain; they do not require compatibility code. TASK-00068 removes the remaining
previous-iteration support; completed TASKs and earlier guidance cannot require that code to remain.

## Package boundary and contracts

- Domain/Application production behavior remains under `src/Domain/AccessControl` and
  `src/Application/AccessControl`, with the existing DDD organization and mirrored tests.
- John approved optional reusable permission/framework integrations under `src/Adapter` in
  [WF-035](../wayfinder/tickets/WF-035-framework-permission-adapter-ownership.md), organized capability-first like
  Common: `Adapter/<Capability>/<Framework-or-Provider-or-Standard>/...`; service-container extension points use
  `Adapter/ServiceContainer/<Framework>/...`. Do not introduce framework-first or DDD aggregate buckets at this
  layer. Reference Common's current canonical destinations, not its deprecated aliases.
- Dependency direction remains `Adapter → Application → Domain`. Keep framework dependencies optional/isolated and
  adapters explicitly enabled; reuse PSR-15 where the actual contract fits. Compose Common's safe-error approach
  without competing reporting/rendering or leaking private exceptions; preserve MCP/OAuth protocol boundaries.
- Consumers still own production persistence adapters, application policy/grants/target scope, transport routes,
  framework bootstrap, keys, queues and runtime. The narrow Adapter approval does not authorize persistence or
  unrelated infrastructure, blanket framework support, a contracts directory or arbitrary production namespaces.
- This is an approved future integration boundary, not existing adapter code. Later approved TASKs own Composer
  autoloading, architecture rules, mirrored `tests/Adapter` suites and exact owned-statement coverage before adapter
  acceptance. Do not change those executable inputs, add dependencies or implement adapters through planning edits.
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
  a success event is dispatched only after that commit. The approved
  [operation-outcome amendment](../tickets/00001-TICKET.md#approved-correction-batch-amendment) governs the correction
  batch, superseding conflicting older guidance within its scope. Before implementing or reviewing that batch,
  obtain its full approved maintainer brief through the established private channel; this public planning summary
  does not replace detailed acceptance or establish implementation. Current consumer composition follows
  [operation outcomes](../../docs/operation-outcomes.md): coarse constant notification text and safe context do not
  sanitize rethrown faults; notification failure cannot replace the primary throwable. Preserve documented exceptions below.
- Human expiry cleanup retains [TASK-00072 C9](../tasks/00072-TASK.md#acceptance-criteria)'s accepted contract;
  see [expiry composition](../../docs/credential-expiry.md). Keep internal provider classification distinct from
  operation-outcome policy.
- Approved replacement exception: [EPIC-00009 D3](../epics/00009-EPIC.md#d3--post-commit-result-behavior), ratified by
  John on 2026-09-27, applies only to replacement recoverable Agent provision and rotation. After a confirmed commit,
  publication failure returns committed metadata with a typed, sanitized warning, not a publication-failure throw.
  Pre-commit failure and indeterminate commit remain distinct; same-key resolution and authorized scheduler discovery
  survive even both publishers failing. This prevents a notification fault disguising committed issuance. Issuance
  confirms neither delivery, enrollment activation nor launch permission; each needs its own confirmed outcome and
  current authorization. Revocation, AuthenticationService and other handlers retain their publication behavior.
  TASK-00046 implements the provision exception and TASK-00048 implements rotation as unreleased work; the old
  raw-return rotation API is removed. TASK-00049/00052 prove package scheduler-only recovery; actual consumer
  qualification remains separate. This guidance grants no independent implementation or release authority.
- For that credential-operation replacement, follow ratified [EPIC-00009 D4](../epics/00009-EPIC.md#d4--bounded-operation-and-integration-policy):
  documented finite defaults, optional validated overrides, and no manual-configuration or additional human-approval
  requirement for routine operation/recovery. Capacity exhaustion must give new work a clear retryable rejection or
  deferral while preserving authorized status and existing-operation recovery. Cleanup must preserve duplicate-issuance
  and stale-delivery defenses. Specify concrete values and implementation choices in requirement/design work and prove
  default/override, capacity/recovery and cleanup/replay behavior before implementation acceptance.
- Agent/operation repositories implement mandatory `getOperationContract()` against a shared persisted cohort and
  qualified actual composition. Every writer holds its cohort fence through the package transaction; direct paths
  cannot bypass it. Delivery/cleanup epochs bind its monotonic generation. Startup checks are not
  admission; consumers separately fence old binaries and qualify real writer races. See the
  [cohort contract](../../docs/agent-operation-cohorts.md). This is unreleased TASK-00056 work, not consumer qualification.
- Operation requests use one fixed Unicode-edge name rule and persisted canonical marker `2`. John's 2026-09-30
  pre-adoption amendment removes historical readers, creation/reader-set selection and backward-compatibility shims.
  Request `canonicalize()` methods take no version argument. Retained keys/tombstones keep original request/issuance
  through cleanup; unsupported markers fail closed without migration or fallback. Storage/destination versions stay 1;
  repositories validate the one supported contract and share its generation/transaction fence. Safe reads do not
  grant writer admission. See the [canonical contract](../../docs/agent-canonical-upgrades.md). Actual persistence,
  authority/sink fencing and restoration qualification remain separate from package proof.
- TASK-00058 adds required nullable `reconciledGeneration` to the existing cohort snapshot. Trusted admission outside
  the restored dataset must verify the exact active storage incarnation and retained operation/receipt/tombstone/order
  history under the same fence; missing/mismatched evidence denies unsafe effects. Controlled restore invalidates
  readiness before replacement, and reconciliation advances generation before resuming. See
  [restoration safety](../../docs/agent-restoration-safety.md). No automatic rollback detector, restore Command or
  prior-contract migration is supplied; modeled package tests do not qualify actual consumer restore/activation/use.
- TASK-00068 removes TASK-00055's superseded legacy-Agent model, recovery marker and old-API stubs. Follow the
  [current Agent contract](../../docs/agent-current-contract.md): validated hydration and unconditional lifecycle
  correlation/cancellation with audit atomicity. User delivery requires explicit claim identity/time and due time;
  use [current worker composition](../../docs/credential-delivery.md), not the removed transport-confirmation Command.
  `ManagedPolicyPlanner` requires all four repositories, including Agent; it has no older optional composition.
  No prior-data conversion, legacy adoption or schema migration is a package obligation.
- Use the [current Agent integration guide](../../docs/agent-integration.md) and
  [complete scenario/evidence inventory](../../docs/agent-operation-evidence.md) for final composition and evidence
  handoff. They distinguish current package assertions, superseded requirements and mandatory unexecuted real-consumer
  qualification. TASK-00059's documentation has independent technical acceptance and behavioral QA at `ed516f9`;
  neither guides nor package passes authorize release, activation/use, consumer adoption, deployment or
  Agent OS TASK-00138 closure.
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
