# Consumed Fight Common contracts

TASK-00110 adopts **johnnickell/fight-common `^1.5.0`** for the existing package. The sufficient tagged minimum is
[v1.5.0](https://github.com/johnnickell/fight-common/releases/tag/v1.5.0), source
`06fb5cd3720af9cf8295198f74040d95b64c5155`, annotated tag object
`ef493612628e9f527bbab8ae5f114c39164df2d3`. This is a published stable release, not sibling HEAD or a development
snapshot. Supplier independent TASK-00181 and TASK-00184–00191 acceptance precedes the tag; release acceptance,
final-main certification and registry-installed composition provide the release bridge. Later supplier
support/deprecation documentation housekeeping is distinct from the immutable released product.

## Affected old-to-canonical inventory

Names in the first eight rows are relative to `Fight\Common\Application\Mcp\`. This is the complete set of
foundation spelling changes actually consumed by current source/tests, not all possible upstream names.

| Previous spelling | Canonical spelling | Actual AccessControl consumers and promises |
| --- | --- | --- |
| `McpProgressReporter` | `Tool\McpProgressReporter` | `GetAgentProfileTool`, `UpdateAgentProfileTool`; Tool tests and protected/interactive fixtures implement `handle(ApplicationData, McpProgressReporter): McpToolOutput` (interactive fixtures may return `McpInputRequired`). `report(float, ?float, ?string): void` and `isCancelled(): bool` remain unchanged; profile Tools do not generate progress or cancellation effects. |
| `McpCapabilityRegistry` | `Capability\McpCapabilityRegistry` | Profile/Tool, Resource and Skill composition tests construct the actual registry from server identity and capabilities. No additional capability advertisement. |
| `McpProtocolException` | `Protocol\Exception\McpProtocolException` | The same composition tests and `ProtocolExclusionTest` catch safe protocol errors; Throwable identity/catchability and request correlation remain unchanged. |
| `McpRequest` | `Protocol\McpRequest` | `AgentToolAvailabilityTest` constructs request identity/method/parameters; metadata and invocation scopes remain request-local. |
| `McpRequestMetadata` | `Protocol\McpRequestMetadata` | Tool/profile/Resource/Skill tests use `fromObject()` with actual protocol version and client capabilities; no capability or authority inferred from metadata. |
| `McpResult` | `Protocol\McpResult` | Tool/profile tests assert `complete()`/`toArray()` result shape; no new response wrapper. |
| `McpServerInfo` | `Protocol\McpServerInfo` | Tool/profile/Resource/Skill tests construct named server identity/version; serialized representation is unchanged. |
| `McpProtocolError`, `McpJsonResponse` | `Protocol\McpProtocolError`, `Protocol\McpJsonResponse` | Resource/Skill tests use safe fixed error factories; `ProtocolExclusionTest` composes exact MCP failure responses separately from ordinary HTTP. |
| `Fight\Common\Adapter\Http\Mcp\McpResponseFactory` | `Fight\Common\Adapter\Mcp\Http\McpResponseFactory` | `ProtocolExclusionTest` constructs the real PSR response factory/stream/diagnostics boundary; errors stay MCP, not JSend. |
| `McpSkillFile::$bytes/$text/$mimeType`, `McpSkillDiscoveryLimits::$maxResultBytes` | `bytes()/text()/mimeType()/maxResultBytes()` | `AgentSkillAvailabilityTest` checks original bytes, text-versus-base64, MIME, digest, size and encoded response budget using canonical reads. `text()` is a Boolean, not content. |

Upstream's exact operation maps are [foundation namespaces](https://github.com/johnnickell/fight-common/blob/06fb5cd3720af9cf8295198f74040d95b64c5155/docs/maintenance/mcp-foundation-namespaces.md),
[public getters](https://github.com/johnnickell/fight-common/blob/06fb5cd3720af9cf8295198f74040d95b64c5155/docs/maintenance/public-property-getters.md)
and [MCP adapters](https://github.com/johnnickell/fight-common/blob/06fb5cd3720af9cf8295198f74040d95b64c5155/docs/components/mcp/index.md).
Their frozen preparation wording predates publication; the actual release identity above establishes availability.

The canonical foundation entry points are **upstream-owned PHP aliases**, not new child interfaces. Implementations
are assignable in both directions. Construction, named arguments, final/readonly flags, factory return identities,
private serialized property scopes and public array/JSON representations remain those of the released runtime
owners. Reflection can therefore report the old runtime name even when callers import the canonical spelling.
Composer eagerly registers aliases; literal container keys still require explicit registration of the requested
spelling. This package adds no alias, wrapper, fallback, warning suppression or copied schema. Native
unserialization remains trusted-only; live services do not acquire a persistence guarantee.

Getter construction/validation is unchanged; reads return existing storage, with immutable object identity and
PHP array copy-on-write semantics. They perform no validation, authorization, I/O or dispatch. Other listed
upstream properties have **no current direct consumer reads** here: SafeHttp metadata/presentation are constructed
through named arguments, OAuth values through factories, and Resource/Skill limits through constructors. Their
classes are not deprecated merely because individual direct properties are. No TABLES constants are consumed.

## Supported contracts deliberately unchanged

| Boundary and actual consumers | Consumed declarations / operation contract |
| --- | --- |
| Domain roots, IDs, payloads and repositories | `UniqueId`, `Uuid`, `EmailAddress`, `ValueObject`, `Arrayable`, `StrictJson`, `ArrayList`, `HashSet`, `ClassName`, Domain/Lookup exceptions; immutable ID/value encodings, equality, validated hydration and collection semantics remain. No identity or value copy is introduced. |
| Commands, Queries, Events and handlers | Common Domain payload/message/metadata identities; Application `CommandHandler`, `QueryHandler`, `EventSubscriber`, `CommandBus`, `SynchronousCommandBus`, `QueryBus`, `EventDispatcher`, `EventDispatchFailed`; actual routing buses/routers in composition tests. Commands return void, Queries return existing safe Views, query absence/fault categories remain deliberate. No handler bypass or new generic inference claim. |
| Transactional Application capabilities | `TransactionalUnitOfWork::commitTransactional(callable): mixed`, independent from Domain repositories; synchronous User/security and Agent operation tests retain atomic writes, rollback/uncertainty distinctions, writer fences and post-commit publication policy. Known callback annotations do not need a new UoW. |
| Synchronous authentication | `PasswordHasher`, `PasswordValidator`, `TokenEncoder`, Auth exception and HMAC adapter composition; sensitive non-serializable inputs, JWT profile, signature framing and nonce lifetimes remain. |
| Application failures and ordinary HTTP | `ApplicationException`, `AuthenticationRequired`, `InvalidInput`, `PermissionDenied`, `PreconditionFailed`, `RateLimitExceeded`, `ResourceNotFound`, `RuleViolation`, `ServerError`, `ServiceUnavailable`, `StateConflict`; safe HTTP diagnostics/policy/projector/metadata/presentation, JSend envelope/factories, native Symfony/Laravel handlers, PSR-15 middleware, separate OAuth failure/response/metadata/scope values. Preserve exact safe status/metadata, one reporting/presentation owner and protocol exclusion. |
| MCP Tool/profile validation | `McpTool`, `McpToolInfo`, `McpToolOutput`, registry/discovery/invocation/invoker/availability/failure map, interactive confirmation/input/state ports, `ApplicationData`, Validation attribute/service/validator/exception. Descriptor identity, schema validation, hidden/unknown equivalence, synchronous update and Query read stay unchanged. Root `McpResponder` and `McpDiagnostics` are **not deprecated** and remain canonical. |
| MCP Resource and whole-Skill | Readable/protected provider, resource info/content/discovery/availability/limits; Skill availability/discovery/limits/file/frontmatter/resources/revision. Preserve whole-entry versus independent Resource grants, exact immutable descriptors, revision binding, bytes/budgets, stream cleanup and fresh request authority. |
| Pagination and returned pages | `Pagination` type and `ResultSet` remain supported. **Pagination's public constructor is genuinely deprecated since 1.4.0**; this adoption does not silently substitute `strict()`, which rejects nonpositive bounds/unsupported directions instead of applying existing defaults/normalization. Existing Query hydration and callers retain their current semantics. This pre-existing validation change is outside the behavior-preserving foundation adoption and needs its owning pagination contract scope (TASK-00132), not import cleanup. `ResultSet` construction is **not deprecated**; its stricter optional factory does not justify changing returned-page semantics here. |

## Prompt feature consumption — TASK-00087

[Agent Prompt authorization](agent-prompt-authorization.md) now consumes the released `Application/Mcp/Prompt`
`McpPrompt`, `McpPromptInfo`, `McpPromptOutput`, `McpPromptAvailability`, `McpPromptDiscovery` and `McpPromptLimits`
contracts. The first sufficient stable minimum is v1.5.0 at the source above; existing `^1.5.0` already suffices.
AccessControl binds actual immutable definitions and direct Agent requirements; Common retains registration/naming,
outer/declared argument validation, selected-provider laziness, visible-catalog cursors, complete bounded output,
central metadata/envelope and safe unknown/denied/internal results. Controlled minimum and ordinary lanes exercise
actual list/get plus independently admitted Query data and inert later-denied links. Work's canonical handoff owns
exact lock/source/check evidence; independent feature Review/QA and actual consumer qualification remain separate.

## Mixed Tool qualification — TASK-00084

The binding extension needs no newer supplier than the current **v1.5.0 minimum** above. Production uses the
existing `McpToolRegistry::{definition,find,available}` and exact `McpToolInfo` identities; no supplier declaration
is copied or changed. `MixedAgentToolsTest` additionally consumes the released final Adapter Tools and owning
Domain Queries below with a controlled QueryBus and real AccessControl request provider:

| Common Adapter Tool (`Adapter/Mcp/Tool/`) | Owning Query (`Domain/Mcp/`) |
| --- | --- |
| `Session/SummarizeExecutionsTool` | `Session/Query/SummarizeExecutions` |
| `Session/ListExecutionsTool` | `Session/Query/ListExecutions` |
| `Session/GetExecutionTool` | `Session/Query/GetExecution` |
| `Phase/ListExecutionPhasesTool` | `Phase/Query/ListExecutionPhases` |
| `Observation/ListPhaseActivityTool` | `Observation/Query/ListPhaseActivity` |
| `Detail/ListCapturedMessagesTool` | `Detail/Query/ListCapturedMessages` |
| `Detail/GetCapturedDetailTool` | `Detail/Query/GetCapturedDetail` |

These tests construct released Application `ExecutionSummary`, `ExecutionPage`, `PhasePage`, `ActivityPage/View`,
`MessagePage`, `CapturedDetail` and `DetailReference` results from supported Domain session/phase/observation,
activity target/timing/result and capture values. Native schema/output validation proves the optional typed activity
facts survive without business-success inference, and metadata-only details retain `body_permitted: false`/null
chunks. Common owns schema, input decoding and protocol output; AccessControl adds only configured Permission
requirements. Explicitly mapped retry proof uses Common's existing invoker/interaction/state/confirmation ports.
Minimum and ordinary lanes, exact lock/source identity and original warnings are retained in canonical
`.runs/handoffs/TASK-00084/work.md`; no actual scoped Query handler/backend/consumer qualification is inferred.

## Live Subscription composition — TASK-00093

[Live disclosure](agent-live-disclosure.md) consumes v1.5.0's actual
`Application/Mcp/Subscription/{McpSubscriptionAdmission,McpSubscriptions,McpSubscriptionFilter,McpSubscriptionExecution}`,
shared `RecoverNotices` Query/handler/recovery ports, DBAL notice/tracking adapters and
`Adapter/Mcp/Http/{McpRequestHandler,McpResponseFactory,McpEventStream,McpResponseEmitter}`. No live manager, tracking
store, source reader or notification protocol is copied. Optional Adapter Agent family glue depends inward on
original-bound current authority and consumer scope. Live Queries use independent producer/source context;
public read/recovery remains separately admitted. Native whole-frame fixtures, minimum/ordinary resolution and
controlled failure/concurrency proof are recorded in canonical TASK-00093 Work evidence; production qualification,
independent Review/QA, Prompt signals, inspection and publication remain distinct.

## Optional notice publication — TASK-00096

The [publication composition](agent-notice-publication.md) consumes Common v1.5.0's actual
`Application/Mcp/Notice/Publication/{NoticePublicationPolicy,NoticePublicationGrant,NoticePublication,
RecordNoticeAndPublish,NoticePublicationStatus,NoticePublicationDiagnostics}` plus routed recording/inspection/
recovery and committed DBAL readers. `AgentNoticePublicationPolicy` supplies fresh independent current operation
Permissions before consumer whole-audience/topic/mode/field scope. No raw-publisher-only substitution, policy or
transport fork is added. Common `Adapter/Socket/{MercureHubPublisher,PrivateMercureHubPublisher}` supplies actual
public/private `Update` translation; optional `symfony/mercure ^0.7` has qualified minimum 0.7.0. Common's sufficient
minimum remains published v1.5.0 at the exact source above. Canonical TASK-00096 Work evidence owns feature-specific
minimum/ordinary locks, PHP runtime, tests and full gate. Controlled hub/consumer fixtures do not qualify actual
browser entitlement, deployed policy/races, receipt or MCP live wire support. Independent feature acceptance stays
separate.

## Qualification and future owners

The isolated exact-minimum Composer lane pins `1.5.0`; the ordinary lane resolves the manifest normally. Both
execute real existing Common registry/invoker/responder, Tool/profile, Resource/Skill and native HTTP composition
over controlled package ports. Exact installed source/Composer bytes are checked against the tag, not inferred
from a version label. Ignored TASK evidence retains the complete per-declaration consumer/blob inventory,
lock/source/runtime identities, commands/logs/exits and full-gate receipt. Package tests are not actual consumer
persistence, production authority/bootstrap, cryptography or deployment qualification. Independent Review and
behavioral QA still own this adoption's acceptance.

Common's new Task/Notice/execution/protected-detail APIs are implemented and released in this supplier tag, rather
than merely planned. Domain repositories/read ports and separate `TransactionalUnitOfWork`, registered CQRS
handlers, meaningful identities/values and outward concrete Tools are the supplier direction. TASK-00184 separates
DBAL UoW, 00185/00186 supply repositories, 00187 handler ownership, 00188 Task projection, 00189/00190 values and
00191 aggregate/adapter homes. TASK-00084 exercises the seven execution Tools through mixed protected composition
in package tests, and TASK-00087 consumes the Prompt contracts described above. TASK-00091 consumes actual Notice
recording/removal admission, Commands, handlers, recorder/remover and DBAL storage in its independently accepted
[mutation composition](agent-notice-authorization.md). TASK-00097 consumes `TaskCreationAdmission`, `TaskUpdateAdmission` and
`TaskReadAdmission` in inward Agent policy. Its feature tests configure actual `CreateTaskHandler`,
`RecordTaskStateHandler` and `GetTaskHandler`, typed Task identities/definitions/states, Commands/Query, payload
policy and real routing buses, with Common DBAL repository/Task transaction/committed reads over SQLite.
See [Task composition](agent-task-authorization.md) for independent grants, guarded worker authority, comparison
epochs, safe faults and consumer limits. TASK-00098 additionally consumes actual `TaskRemovalAdmission`,
`RemoveTasksHandler`, `RemoveTasks`, `TaskSelection` and `TaskDataRemoved` from the same sufficient v1.5.0 tag.
Its independent Agent Permission/current consumer scope runs inside Common's whole-selection admission before
atomic row removal. Common deliberately maps removal-admission faults to fixed `TaskRejected`; authorized missing
identities are no-ops, not fabricated removal facts. Retained retry/comparison material is removed with the
aggregate, without permanent identity memory. Its canonical Work handoff owns feature-specific minimum/ordinary
and concurrent SQLite qualification. DBAL remains development-only here; no production persistence is copied.
TASK-00092 independently qualifies actual summary/list/detail/recovery Queries and `notices.recover` through
[retained read composition](agent-notice-recovery.md), with inward Agent Permission and trusted consumer scope.
TASK-00100 now composes actual `TaskCancellationAdmission`, `RequestTaskCancellationHandler`,
`GetTaskCancellationHandler`, their owning Command/Query and `McpTaskCancellation` method from the same v1.5.0 tag.
Independent mutation/observation authority runs before protected lookup; Common owns durable intent, committed-reader
confirmation, terminal/repeat no-ops and safe method bounds. Its canonical Work handoff owns minimum/ordinary and
cross-process SQLite/race/fault qualification; consumer stopping is not supplied or certified.
Other TASK-00089–00090/00094–00095 and TASK-00101–00102 must inspect their exact declarations, configure actual
Common buses and independently qualify each operation/family. This baseline clears only TASK-00110's historical
supplier hold, not those feature
holds, grant requirements, live-runtime requirements or separate generic typing/returned-page evidence gaps.
