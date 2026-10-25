# Fight Common MCP permission integration evidence

## Question

Do Fight Common's newer MCP capabilities and Tools support permission enforcement, and what must a future
AccessControl release add to integrate them without moving application policy into Common?

## Short answer

**Common provides enforcement seams; AccessControl does not yet bind all of them.** Existing Tool availability
precedes selected-Tool work, execution Queries independently enforce supplied scoped admission, Resources/Skills
have independent visibility/read checks, and the in-progress Prompt implementation checks list/get availability.
Remaining Subscriptions/Tasks plans explicitly require current, separate permissions. Those plans are not implemented
capabilities or evidence that live revocation already works.

The concrete integration blocker is AccessControl's attribute-only Tool catalog: all seven Common execution Tools
register successfully with Common but are rejected by `AgentToolPermissionCatalog`. They lack AccessControl's
`RequiresAgentPermission` attribute and are final. This is an expected **fail-closed composition gap**, not a
permission bypass. Do not fix it by allowing unannotated Tools or adding an AccessControl dependency to Common.

## Baseline and method

Inspection date: **2026-10-25**. AccessControl source baseline: `4858dde7fb4e3b364157c81e808421532cccffd9`, initially
clean `develop`; Composer requires Common `^1.3`, with local installed **v1.4.0**. Common sibling checkout:
`../fight-common`, HEAD **`a38cf56c466e218ac56fbc07f12e4915c13c8a2f`**, branch
`feature/task-00157-serve-mcp-prompts`. Its Prompts implementation and associated documentation were uncommitted
(and became staged during inspection). No sibling files, index, dependencies or branch were changed by this work.

Evidence combines source traces, selected existing test bodies, Common's execution-evolution map/EPICs and remaining
TASK permission/mechanics sections. Local tags through v1.4.0 and the changelog distinguish released from unreleased
work; no remote publication, Packagist or deployed consumer state was checked. Common's closed evolution map contains
historical pre-implementation prose; current source and TASK checkpoints take precedence for implementation state.

A research-only host PHP **8.5.12** probe loaded Common's checkout autoloader plus AccessControl source, constructed
each real execution Tool and Common registry, then attempted the existing AccessControl catalog. **7/7** produced
the expected missing-permission-metadata rejection; **0 Query calls**. The probe also confirmed all seven classes
are final. This is bounded composition evidence, not the canonical Docker build, independent Review/QA, a new test
suite or actual authentication/storage qualification. Script, output and source hashes are retained under
`.runs/notes/mcp-permission-integration/`. Existing product tests cited below were inspected, **not rerun**.

## Findings

### Capability and permission matrix

| Surface | Observed maturity | Common enforcement boundary | AccessControl integration consequence |
| --- | --- | --- | --- |
| Tools, protected input/confirmation, request progress | Released baseline in v1.3/v1.4 | Mandatory `McpToolAvailability`; discovery filters before paging, invocation selects before input validation; retry selects before restoration/consumption/resume | Existing annotated Tools work through the current catalog/provider. Availability is not business-target authorization. |
| Resources list/read | Released baseline | `McpResourceAvailability` before disclosure/open; optional provider-owned protection; exact read does not require listing | No AccessControl Resource binding exists. URI possession, cursors and Tool-returned links grant no read authority. |
| Skills list/get and member Resources | Released baseline | `McpSkillAvailability` on whole entry plus general Resource availability on every member; member reads repeat both applicable checks | No AccessControl whole-Skill binding exists. Loading instructions does not authorize activation, execution or linked Tools. |
| Five safe execution Tools | Implemented, unreleased TASK-00155 | Tool availability plus fresh `ExecutionReadAdmission` in shared Query handlers | Need a binding for Common-owned Tools and consumer-owned project/session/phase/activity/reference scope. |
| Two protected execution Tools | Implemented, unreleased TASK-00156; HEAD merges PR #235 | Separate `DetailReadAdmission` and `DetailAccess` metadata/body grants per page/chunk | A timeline or Tool grant must not imply body permission; protected body access must remain independently selectable. |
| Prompts list/get | Uncommitted TASK-00157 implementation; current checkpoint pending independent Review/QA | `McpPromptAvailability` before listing/get; selected declared arguments before provider; application-backed provider retains Query authorization | No AccessControl Prompt binding exists. APIs remain provisional until tagged; links need later independent Resource admission. |
| Retained notices/polling/live changes/subscriber inspection/Mercure | Approved planning, TASK-00158–00165 | Independent recording/removal/read/recovery/listen/disclosure/inspection/publication decisions; fresh checks before queued emission | No implemented Common integration API to adopt yet. Do not reuse one coarse listen/read Permission for all operations. |
| Durable Tasks/input/cancel/optional full-state live output | Approved planning, TASK-00166–00171 | Independent create/producer/read/input/cancel/remove/continuation/disclosure authority; complete negotiated activation | No implemented Common integration API to adopt yet. Handles, earlier starts and generic change-feed grants are not Task-state authority. |

Sources: [Common MCP guide][C1], [execution inspection][C2], [evolution map][C3],
[Subscriptions EPIC][C4], [Tasks EPIC][C5], [AccessControl composition](../../../docs/agent-mcp-authorization.md).
Prompt evidence is local uncommitted source described below, not a published contract.

### 1. Existing Tool enforcement is usable, but binding is attribute-only

`McpToolInvoker::invokeInitial()` calls `select()` before schema/rule validation; `select()` asks availability and
uses the same unknown-Tool error for unavailable and missing names. Retry privately opens state to identify the
Tool, then checks availability before restoring arguments or consuming confirmation. Common's registry lookup APIs
and direct `handle()` calls are not authorizers. [Invoker][C6]

AccessControl's [catalog](../../../src/Application/AccessControl/Agent/AgentToolPermissionCatalog.php) enumerates the
complete Common registry, reflects only `RequiresAgentPermission` on `handle()`, and rejects missing metadata.
It binds requirements to the **exact immutable definition object**, not merely a same-name string. Repeatable
attributes are conjunctive. The [availability implementation](../../../src/Application/AccessControl/Agent/Security/AgentToolAvailability.php)
exposes only a Boolean over direct Agent Permissions; it does not grant User Roles or convert OAuth scopes.

The seven Common-owned Tools are `execution.summarize`, `execution.list`, `execution.get`, `execution.phases`,
`execution.activity`, `execution.messages`, and `execution.detail`. Their final classes cannot be subclassed to
attach AccessControl attributes. Common correctly has no reverse dependency on AccessControl. The probe verifies
that the existing protected composition rejects each, including the five safe Tools; it does not establish an
unprotected route or show that permissive consumer policy is secure. [Catalog examples][C2], [protected Tool][C7]

### 2. Tool permission and scoped data permission are different checks

`GetExecutionHandler` calls `ExecutionReadAdmission::currentAccess(selection)` before its reader. Protected
`GetCapturedDetailHandler` independently calls `DetailReadAdmission::currentAccess(selection)`. The access types
represent scoped project/session/phase/activity/reference decisions; `DetailAccess` distinguishes metadata from
body grants. A body grant without metadata permission discloses nothing. Safe timeline policy neither automatically
grants nor vetoes an independently authorized protected read. [Shared reads][C8], [protected reads][C9]

The protected journey test explicitly varies body/metadata authority, forged relationships, later pages/chunks,
Tool availability and mirror-versus-invocation availability; it asserts no Query admission on Tool denial.
Those are real package test assertions, not an AccessControl-backed consumer integration already qualified. [Test][C10]

AccessControl's current direct Permission snapshot does not model another application's project membership,
session sharing, retention rules or Task ownership. Consumers must supply those relationships through Common's
admission ports. Adding a broad Permission must not manufacture `all projects` or `all protected bodies` grants.
Recording and removal have separate admission ports and are not exposed by these seven read-only Tools.

### 3. Resources, Skills and Prompts are independently protected surfaces

Resource read performs exact all-provider metadata lookup, rejects conflicting ownership, then checks provider
protection plus general availability before opening. Skills discovery requires whole-entry availability and all
member Resource decisions; individual Resource reads repeat their applicable protection. These cannot be secured
solely by guarding `tools/call`. Catalog metadata, counts, links, historical URIs and private cached results also
need current policy. [Resource source][C11], [Skill source][C12]

Local `src/Application/Mcp/Prompt/McpPromptDiscovery.php::get()` checks availability before declared-argument
validation and `provider->get()`. Listing filters before visible-catalog hashing/pagination; unknown/denied get
uses safe invalid parameters. Local `McpPromptDiscoveryTest` verifies denied direct retrieval after listing,
while `McpPromptJourneyTest` covers independent application and later Resource authorization. These source files
and `docs/mcp-prompts.md` belong to active Common TASK-00157, not the v1.4.0 API. The inspected discovery file's
SHA-256 is `1a2c64677ffae040a269dc550a5c9861adffbe41ce8f1cd991158c735e3bd6ee`.

The AccessControl source inventory contains only the Tool availability/catalog binding, not implementations of
`McpResourceAvailability`, `McpSkillAvailability`, `McpPromptAvailability`, `ExecutionReadAdmission` or
`DetailReadAdmission`. Consumers can already implement released neutral ports themselves; absence of reusable
AccessControl integration is not absence of Common enforcement.

### 4. Current request snapshots are not live revocation

[CurrentAgentPrincipalProvider](../../../src/Application/AccessControl/Agent/Security/CurrentAgentPrincipalProvider.php)
authenticates a signed request, consumes its nonce and caches an immutable successful principal for that request.
`AgentToolAvailability` also remembers failed resolution and its resolved principal. That is the accepted contract
for short requests and fresh retry requests; authority changes after resolution do not cancel already admitted work.

Common's remaining plans require current access **before every live disclosure**, including queued notices and
full Task state. Keeping the existing provider for an entire stream/job would freeze authority. Constructing a
fresh provider repeatedly over the same signed request would instead replay an already-consumed nonce. Neither is
a valid drop-in renewal strategy. A future design must separate original authentication from authoritative
revalidation at disclosure/continuation boundaries, bind it to trusted caller/credential identity, and define safe
behavior on revocation, rotation, Permission changes, scope changes and resolver failure. Exact APIs and race/fence
semantics are an open decision, not a claim that the current provider is defective. [Live requirements][C4], [C5]

### 5. Remaining planning has substantive permission requirements, not just protocol flags

The selected permission/mechanics sections of **TASK-00158–00171** and their owning EPICs require:

- Separate notice producer/removal, summary/history/detail/recovery, listen, subscriber-inspection and Mercure
  publication authority. A subject/intended-recipient/author/causation reference is data, not a grant. Apply scope to
  totals and history-loss/continuation metadata too. Unproven recovery access continuity must not claim complete catch-up.
- Reauthorization of queued notifications and catalog-change signals themselves, avoiding concealed-activity leaks.
  Heartbeats and stream admission are not permission leases; source/tracking failure cannot look healthy.
- Separate Task creation, producer updates, status/result/input disclosure, input admission, cancellation, removal
  and worker continuation. Full Task live state has the same sensitive disclosure boundary as `tasks/get`, not the
  general safe-notice feed or indiscriminate Mercure publication. Current read permission cannot authorize mutation.
- Permission checks on repeat/no-op/missing-target paths; safe cross-scope/unknown/denied behavior; cancellation
  intent distinct from stopping or rollback; no authority from client capability declarations or SUPER_ADMIN labels.

The EPICs contain complete accepted TASK decompositions, but the corresponding Subscriptions/Tasks production
capabilities are absent from the inspected source tree. Exact future admission types and supported runtime behavior
must be re-inspected when implemented. Do not invent those PHP signatures now. [C3], [C4], [C5]

## Follow-up — Agent OS middleware, framework hooks and safe errors

John accepted WF-033's freshness boundary, then explicitly asked about framework-native adapters and compatibility
with Common's safe-error handling. This follow-up samples source, not a runtime qualification or authorization to
modify another repository. Agent OS was clean at `9ae7681b13c7a4e9ca38218f3576fef838e5973d`; Common had advanced to
`ccdb233f35f22745e7aff8a48bc4e320334a1022`. The earlier investigation/probe above retains its original baseline.

### Agent OS compatibility

Agent OS `ApiActionSecurity` is PSR-15 middleware. It inspects matched Action access metadata without constructing
the Action, validates declared Permission references, authenticates the bearer credential, constructs a fresh
`CurrentPrincipalProvider` inside `process()`, checks every required Permission through `PermissionGuard`, and
passes the request-local authentication/provider/security context as request attributes. Its route group orders
security before input validation, endpoint controls and Action dispatch. `ActionAccess` rejects malformed/mixed
security declarations; `PermissionGuard` has no administrator role-name bypass. [Middleware][A1], [route order][A2]

The inspected `ApiActionSecurityTest::testAuthorityFreshness()` changes Role Permissions and revokes the session
between requests on the same application; `testOneRequestSnapshot()` checks request-local resolution. These are
existing test assertions, not tests rerun here. This is a **human bearer/session** composition using
`CurrentPrincipalProvider`, not an already-qualified Agent HMAC/MCP integration using
`CurrentAgentPrincipalProvider`. The architectural pattern fits WF-033; authentication credentials and principal
policies must not be conflated. Middleware admission alone does not establish authority for an entire live stream,
queued disclosure or background job. Those call the separate current-authority check at protected boundaries. [A3]

### Framework hooks and ordering

| Target | Appropriate candidate hook | Evidence/qualification boundary |
| --- | --- | --- |
| Slim/PSR applications | PSR-15 middleware after route selection and before protected validation/dispatch | Actual Agent OS composition above; do not copy its Slim route inspector into a supposedly portable core. |
| Yii 3 | Reuse PSR-15 middleware with framework route/context composition | `project-yii` at `d810303ebeeea9c1114e77ac2025da3a3138d197`, `src/Adapter/Container/HttpApplicationProvider.php` uses Yiisoft MiddlewareDispatcher/Router and Common PSR middleware. This is Yii 3, not a Yii 2 ActionFilter claim. |
| Symfony | Kernel request listener/subscriber after routing, before controller resolution when pre-construction denial is promised | Installed `HttpKernel::handleRaw()` dispatches kernel.request, resolves controller, then dispatches kernel.controller. RouterListener runs on kernel.request at priority 32 in the inspected dependency. Exact registration/order, subrequests and supported controller descriptors need qualification; copying the validation subscriber's kernel.controller hook cannot promise pre-construction denial. |
| Laravel | Native request/route middleware and explicit application registration | `project-laravel` at `79df4078ddec46624563545749bd4f10b6eb8d45`, `bootstrap/app.php` exposes native withMiddleware configuration. Qualify controller/validation lifecycle rather than assuming identical Slim ordering. |
| CodeIgniter 4 | Native before filter | Installed `CodeIgniter/Filters/FilterInterface.php` defines before(request, arguments), able to return a response to stop execution, and after(request, response, arguments). Actual route/filter order and request lifetime need qualification. |

These are candidate integration seams, not five existing AccessControl adapters or installed-framework support
claims. Follow-up inspection of Common's accepted
[ADR 0023](https://github.com/johnnickell/fight-common/blob/ccdb233f35f22745e7aff8a48bc4e320334a1022/planning/adr/0023-service-container-and-framework-adapter-namespaces.md)
confirms optional reusable adapters may live in Common; it supersedes ADR 0021's blanket no-new-shared-adapter rule
and updates ADR 0019's namespace destinations. Runtime adapters remain capability-first:
`Adapter/<Capability>/<Framework-or-Provider-or-Standard>/...`; container extension points use
`Adapter/ServiceContainer/<Framework>/...`. Native lifecycle differences justify adapters; equal counts do not.

At follow-up intake AccessControl required explicit approval for a production Adapter layer. John subsequently
selected optional integrations **inside AccessControl's `src/Adapter`**, modeled on Common's capability-first layer,
while retaining DDD organization inward. [WF-035](../tickets/WF-035-framework-permission-adapter-ownership.md) and
the [profile](../../agents/project-profile.md#package-boundary-and-contracts) record the narrow approved amendment.
This does not adopt Common's 1.x aliases, approve unrelated infrastructure/persistence or supply adapter code.
No starter source was edited.

### Safe errors: reuse Common's boundary, preserve protocol contracts

**Timing clarification:** the safe-error baseline is already in **Common v1.4.0**, not only the next version.
Its changelog and local tag tree include the eleven neutral Application categories, `SafeHttpErrorPolicy`,
`SafeJSendErrorMiddleware`, `SafeSymfonyExceptionSubscriber` and `SafeLaravelExceptionHandler`. Any further
next-version behavior still needs separate inspection; absence of a new change from this sample is not proof that
none is planned. [Common safe HTTP guide][E1], [failure vocabulary][E2]

Common's safe policy classifies declared types (including explicit consumer subtypes), not arbitrary exception
messages/codes/causes. Unknown failures use generic 500. Public defaults are fixed; optional trusted projections
must deliberately select safe data. One required redaction-aware diagnostics owner receives private failures;
logs/formatters must not see unredacted exception context first. A missing required 401 challenge or 405 Allow
fails safely to generic 500. Retry hints are not retry authority or automatically public headers. Safe handlers
are **explicit opt-ins**; old raw-message handlers remain unsafe and are not an acceptable fallback. [Policy][E3]

Agent OS currently has its own `ApiFailureMapper`/`ApiFailureMiddleware`. Its application `PermissionDenied` and
`AuthenticationRequired` extend native RuntimeException, **not** Common's new category types. Common would treat
those unadapted types as unknown 500. Existing Agent OS mappings also have consumer-specific status/envelope/body,
correlation, no-store and reporting behavior; they are not automatically identical to Common's defaults. A projector
cannot by itself reclassify an unknown type, because policy selects status first. Adoption therefore needs explicit
category/translation choices, 401 challenge policy and preservation or deliberately approved change of the consumer
contract. Do not stack competing renderers/reporters or silently migrate Agent OS under this package's authority.
[Agent OS mapper][A4], [failure boundary][A5]

Permission adapters should expose deliberate outcomes/category failures and delegate ordinary HTTP rendering to
Common's selected safe integration. Do not convert every storage/authentication fault into permission-denied or
publish raw exception text. Domain exceptions remain Domain-owned; Application categories cannot become a Domain
dependency. MCP already has its own generic protocol failures, denied/unknown concealment and exact-class Tool
failure mapping to constant text. **Do not replace those with JSend/HTTP status mappings** or assume a Common category
subclass automatically matches an MCP Tool failure map. OAuth responses likewise retain their protocol boundary.
Reusing safe principles is not merging transport envelopes. [E1], [E2]

The user requested safe-error compatibility/adoption consideration as a constraint on this planning.
[WF-035](../tickets/WF-035-framework-permission-adapter-ownership.md) now records approved same-package optional
adapter ownership and capability-first layout. Exact failure mapping/migration, supported versions, selected release
tranches and runtime evidence remain downstream. No consumer migration or implementation follows from the approved
structural boundary. No runtime suites were run for this follow-up.

## Initial integration proposal — release staging superseded

The staged-release recommendation below is retained as research history, not the current release plan. John
subsequently selected **one combined release including Subscriptions and durable Tasks** in
[WF-034](../tickets/WF-034-mcp-integration-release-scope.md), waiting for required Common implementation,
qualification and tagged contracts. He also selected all five framework families: Slim, Yii 3, Symfony, Laravel
and CodeIgniter 4. WF-033/WF-035 own accepted tooling/adapter guarantees. John subsequently approved the single
**Permission-Aware MCP Integration and Framework Adapters** EPIC destination, leaving the release number unassigned.
Actual dependency minima and supported framework ranges require later evidence; this note does not qualify them.

1. **Preserve ownership.** Common keeps neutral protocol/access ports and shared Queries. AccessControl supplies
   reusable Agent Permission evaluation/bindings inside its existing Domain/Application boundary. Consumers retain
   authentication selection, resource relationships, scope policy, public/private choices, transports and persistence.
   Human policies remain distinct; an Agent must not inherit human SUPER_ADMIN authority.
2. **First solve Common-owned Tool binding.** Prefer an explicit immutable requirement registration against the
   same Common registry, alongside existing attributes. Preserve complete coverage, conjunctive semantics, exact
   definition identity, unknown/foreign denial and missing/malformed/conflicting mapping rejection. Compare this with
   thin attributed delegation wrappers; do not duplicate Tool schemas/CQRS logic, silently override attributes or
   weaken missing-metadata rejection. WF-033 now accepts the explicit-mapping direction alongside attributes,
   but selects no exact public API.
3. **Add selected family-specific bindings.** Resource exact identities, whole-Skill entries and Prompt definitions
   need their own neutral ports, stable requirement association and unknown/missing fail-closed rules. Reuse one
   trusted short-request principal without sharing filtered pages across callers. Keep linked reads and application
   Queries independently authorized. Avoid inventing annotations where Common's objects/providers have no such seam.
4. **Keep scope composition explicit.** A reusable permission gate can precede consumer-owned relationship policy;
   it cannot implement project/session/Task grants by copying request selectors into an access object. Decide which
   helper belongs in AccessControl versus illustrative consumer wiring, without adding a project/tenant model here.
5. **Stage live/durable integration separately.** Design trusted revalidation before adopting Subscriptions/Tasks;
   retain the existing short-request contract. Adopt actual future ports only after source/API verification. Keep
   polling usable without live subscriptions, and ordinary Prompts independent of Tasks/live delivery.
6. **Select a future release only after scope approval.** v0.8.0 remains the current published AccessControl release.
   Do not select v0.9.0 or a Common version by inference. `^1.3` allows later 1.x releases but also permits versions
   without these new classes: each slice must require at least the first tagged Common version containing its used
   APIs and verify that minimum. Any narrowing/breaking API change follows AccessControl's pre-v1 minor policy;
   no historical compatibility shim, untagged Composer adoption, release or consumer deployment is authorized here.

## Required later acceptance evidence

- Real Common-owned execution Tools plus existing attributed profile Tools in one complete protected catalog:
  deny before validation/Query/provider/open/progress; reject missing, duplicate/conflicting and foreign mappings.
- Actual AccessControl principal/Permission resolution composed with neutral family hooks, not only Boolean stubs;
  independent Query scope and metadata/body grants, cross-project denial and protected-path diagnostic safety.
- Fresh pages/get/read/chunks and saved URI/link/cursor use after grant/revocation/rotation; protect totals and hidden
  catalog signals as well as bodies. Prove client arguments and `_meta` cannot supply authority.
- Long-lived revalidation after initial disclosure and while frames are queued; no stale provider singleton or nonce
  reuse; stop safely on lost authority/fault. Durable Tasks separately reauthorize reads/input/cancel/continuation.
- Finite complete encoded output, safe failure mapping, actual consumer lifetimes/storage/cryptography/runtime
  qualification, and minimum-supported Common release verification. Product suite/default build obligations and
  independent Review/QA apply to later implementation, not to this planning-only inspection.

## Open questions and handoff

[WF-032](../tickets/WF-032-common-mcp-permission-seams.md) records the completed factual investigation.
[WF-033](../tickets/WF-033-common-owned-mcp-permission-bindings.md) now records accepted bindings/ownership/freshness.
[WF-035](../tickets/WF-035-framework-permission-adapter-ownership.md) records approved optional `src/Adapter`
ownership, capability-first organization and safe-error compatibility constraints.
[WF-034](../tickets/WF-034-mcp-integration-release-scope.md) now records the approved combined release/all-five-framework
scope and single EPIC destination, with no assigned release number. Grill subsequently wrote that destination as
[EPIC-00014](../../epics/00014-EPIC.md); all map decisions/EPIC handoffs are complete and the map is Closed. John
subsequently approved the complete eight-TICKET split, now written as [TICKET-00025](../../tickets/00025-TICKET.md)
through [TICKET-00032](../../tickets/00032-TICKET.md). John subsequently approved TICKET-00025's single-TASK plan,
written as [TASK-00084](../../tasks/00084-TASK.md), needs-info for actual qualified/tagged Common execution contracts
and dependency-minimum evidence. John then approved TICKET-00026's complete plan, written as
[TASK-00085](../../tasks/00085-TASK.md) → [TASK-00086](../../tasks/00086-TASK.md), Resource then whole-Skill/member
admission; neither depends on TASK-00084. Their named hooks appear in inspected Common tags, with actual minimum/
behavior qualification left to implementation. John then approved TICKET-00027's full single-TASK plan, written as
[TASK-00087](../../tasks/00087-TASK.md), needs-info for qualified/tagged Common Prompt contracts and minimum evidence,
with no local TASK dependency. John then approved TICKET-00028's full plan, written as
[TASK-00088](../../tasks/00088-TASK.md), ready with no local blocker or new Common runtime API prerequisite; actual
consumer handoff/lifetime/scope/fence qualification remains separate. John then approved TICKET-00029's full plan,
written as [TASK-00089](../../tasks/00089-TASK.md) / [TASK-00090](../../tasks/00090-TASK.md) for scoped timeline and
independent protected metadata/body reads. Both consume TASK-00084 and are needs-info for qualified/tagged Common
scoped reads/minimum evidence, neither consuming the other or TASK-00088 for ordinary requests. John then approved
TICKET-00030's full six-TASK plan, written as [TASK-00091](../../tasks/00091-TASK.md)–[TASK-00096](../../tasks/00096-TASK.md)
for recording/removal, retained inspection/recovery, live Tool/Resource/Skill disclosure, Prompt signals, subscriber
inspection and optional publication. All retain qualified/tagged Common/minimum holds and approved local supplier
edges; the owning TICKET records the matrix. At this To Tasks inspection Common HEAD was
`2c4d1d529f6057f0a4ef2d7483b92d0327a58403`: recording/removal/inspection ports exist in source; recovery/live/notice
publication remain planned, and latest inspected local tag v1.4.0 has no Notice declarations. Existing raw Mercure
publishers are not the full notice-publication seam. This is bounded local evidence, not remote release/consumer
qualification. John then approved TICKET-00031's full six-feature plan, written as
[TASK-00097](../../tasks/00097-TASK.md)–[TASK-00102](../../tasks/00102-TASK.md), creation/producer/protected reads,
removal, input/continuation, cancellation/intent, complete MCP start/reconnect and optional full-state live output.
All retain qualified/tagged Common/minimum holds and approved local supplier edges; its owning TICKET records the
matrix. Proposal inspected Common `bb734378b48925fd2648937739d9d82102d2db17`; approval persistence rechecked
`13cd3ea76327dd8776a445c9436b4da1d2b7eef1`: durable Task suppliers remain planned, no corresponding production APIs/
method handlers were found, and latest inspected local tag v1.4.0 supplies no durable Task APIs. This is bounded local
evidence, not remote release/independent acceptance/consumer qualification. John then approved TICKET-00032's full
four-feature plan, written as [TASK-00103](../../tasks/00103-TASK.md) shared PSR-15 with separate Slim/Yii 3 qualification,
[TASK-00104](../../tasks/00104-TASK.md) Symfony, [TASK-00105](../../tasks/00105-TASK.md) Laravel and
[TASK-00106](../../tasks/00106-TASK.md) CodeIgniter 4. All ready-for-agent: A has no local blocker; native peers consume
only its inward contract/initial Adapter wiring and share order 104. Existing SecurityContext and tagged Common
v1.4.0 safe policy/PSR-15/Symfony/Laravel/native response APIs supply the direction, not blanket native qualification.
Symfony controller resolution precedes kernel.controller; Laravel controller-middleware discovery can instantiate
controllers before route middleware. A native CI4 response helper alone is not a reporting/redaction owner. The
native slices retain these order/coverage proofs, actual sufficient minima and consumer gaps; no MCP/TASK-00088
prerequisite for ordinary requests. All map decisions/EPIC/TICKET/TASK planning is now accepted/written (23 features);
the EPIC's current handoff points to `/skill:next` for Board-based selection, not automatic implementation. The initial research snapshots remain historical;
the EPIC records later bounded
local Common maturity without asserting remote/release/consumer qualification. No TASK, grants, exact new API or implementation is created by
this research note.

## Sources

Common links below pin the inspected committed baseline, not a tagged release; local uncommitted Prompts are
identified above and have no immutable published source link yet. AccessControl links refer to this checkout.

[C1]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/docs/components/mcp/index.md
[C2]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/docs/mcp-execution-inspection.md
[C3]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/planning/wayfinder/mcp-execution-introspection-map.md
[C4]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/planning/epics/00011-EPIC.md
[C5]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/planning/epics/00012-EPIC.md
[C6]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/src/Application/Mcp/Tool/McpToolInvoker.php
[C7]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/src/Application/Mcp/Execution/GetCapturedDetailTool.php
[C8]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/docs/execution-reads.md
[C9]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/docs/execution-protected-reads.md
[C10]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/tests/Functional/McpProtectedExecutionJourneyTest.php
[C11]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/src/Application/Mcp/Resource/McpResourceDiscovery.php
[C12]: https://github.com/johnnickell/fight-common/blob/a38cf56c466e218ac56fbc07f12e4915c13c8a2f/src/Application/Mcp/Skill/McpSkillDiscovery.php
[A1]: https://github.com/johnnickell/fight-agent-os/blob/9ae7681b13c7a4e9ca38218f3576fef838e5973d/src/Adapter/Http/Middleware/Api/Auth/ApiActionSecurity.php
[A2]: https://github.com/johnnickell/fight-agent-os/blob/9ae7681b13c7a4e9ca38218f3576fef838e5973d/config/routes/01-api-v1.php
[A3]: https://github.com/johnnickell/fight-agent-os/blob/9ae7681b13c7a4e9ca38218f3576fef838e5973d/tests/Integration/Postgres/ApiActionSecurityTest.php
[A4]: https://github.com/johnnickell/fight-agent-os/blob/9ae7681b13c7a4e9ca38218f3576fef838e5973d/src/Adapter/Http/Api/Failure/ApiFailureMapper.php
[A5]: https://github.com/johnnickell/fight-agent-os/blob/9ae7681b13c7a4e9ca38218f3576fef838e5973d/src/Adapter/Http/Middleware/Api/ApiFailureMiddleware.php
[E1]: https://github.com/johnnickell/fight-common/blob/ccdb233f35f22745e7aff8a48bc4e320334a1022/docs/safe-http-errors.md
[E2]: https://github.com/johnnickell/fight-common/blob/ccdb233f35f22745e7aff8a48bc4e320334a1022/docs/application-failures.md
[E3]: https://github.com/johnnickell/fight-common/blob/ccdb233f35f22745e7aff8a48bc4e320334a1022/src/Adapter/Http/Error/SafeHttpErrorPolicy.php
