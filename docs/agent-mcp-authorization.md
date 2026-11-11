# Agent-protected MCP Tool composition

This API originated in v0.5.0 through [TASK-00035](../planning/tasks/00035-TASK.md), first verified against
Common v1.3.0 (`7de6cad6e8a9752973ad9f8e27e285b0c1510582`). The current checkout requires Common `^1.5.0` (tagged source
`06fb5cd3720af9cf8295198f74040d95b64c5155`), including its seven execution Tools and canonical admission APIs.
[TASK-00108](../planning/tasks/00108-TASK.md) moves protocol bindings outward without changing wire behavior. It supplies authorization
metadata and availability, **not** protocol policy, an endpoint, OAuth mapping or a consumer adapter.
[Self-service profile Tools](agent-profile-tools.md) are a separate consumer of this boundary. Their request-bound
instances require the same fresh provider/request as availability. Independent review/behavioral QA and actual
consumer qualification remain separate from builder tests.

[Protected detail admission](agent-protected-detail.md) separately intersects direct metadata/body Permissions and
consumer structural scope through Common's owning Queries. Keep metadata-only Tools available without body Permission;
coarse Tool availability and timeline references never grant protected data.

[Resource authorization](agent-resource-authorization.md) is independently composed through Common's Resource hook.
Its operational-failure behavior intentionally differs from this Tool-only neutral-denial policy.
[Current continuation admission](agent-continuation-authorization.md) separately protects each new queued disclosure,
background step and retained-input consumption. Ordinary request availability is not that synchronized action fence;
actual Common Subscription/Task integration remains downstream work.

## Public contracts

All three types now live under `Fight\AccessControl\Adapter\Mcp\Tool\Agent`. Update imports, attributes and
registrations directly; retired Application names have no aliases. Authentication, Permission policy and synchronized
continuation remain inward in Application/Domain:

| Type | Contract |
| --- | --- |
| `Attribute\RequiresAgentPermission` | Method-only, repeatable metadata. Constructor `string $permissionName`; `getPermissionName(): PermissionName` returns the existing validated canonical value. Every declaration is required. |
| `AgentToolPermissionCatalog` | Immutable static requirements for one `McpToolRegistry`. Constructor `AgentToolPermissionCatalog(McpToolRegistry $registry, array $permissionBindings = [])` accepts explicit canonical Tool-name bindings of shape `array<string, non-empty-list<string>>`. `requirementsFor(McpToolInfo): ?array` returns a nonempty list of Permission names or null for an absent/foreign definition. |
| `AgentToolAvailability` | Request-scoped `McpToolAvailability`. Constructor takes the catalog, a fresh `CurrentAgentPrincipalProvider`, the request's `SignedAgentRequest`, and a nonempty correlation ID. `isAvailable(McpToolInfo): bool` is the only decision Common receives. |

Declare requirements on each Tool's `handle()` alongside Common's metadata, for example:

```php
#[McpToolInfo('reports.read', 'Read a report', ['type' => 'object'], ['type' => 'object'])]
#[RequiresAgentPermission('VIEW_REPORTS')]
#[RequiresAgentPermission('VIEW_AUDIT')]
// Import Fight\Common\Application\Mcp\Tool\McpProgressReporter.
public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
```

This is a method declaration excerpt; the consumer implements its body and supplies the corresponding imports and
Common `McpTool` implementation. Metadata alone does not intercept calls to `handle()`; callers must use the
composed Common discovery/invoker path. Repeated equal requirements are harmless but do not introduce any-of semantics.
Permissions match existing canonical `PermissionName` values against direct Agent authority, never Roles or OAuth scopes.

## Static composition

Construct the registry once from the complete explicitly exposed Tool set, then construct its catalog:

```php
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolPermissionCatalog;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;

$registry = new McpToolRegistry($tools);
$catalog = new AgentToolPermissionCatalog($registry, $permissionBindings);
```

`$permissionBindings` is an explicit map for Tools without AccessControl attributes. For example, when `$tools`
contains Common's seven unmodified execution Tools and the attributed profile Tools, an application may configure:

```php
$permissionBindings = [
    'execution.summarize' => ['VIEW_EXECUTIONS'],
    'execution.list'      => ['VIEW_EXECUTIONS'],
    'execution.get'       => ['VIEW_EXECUTIONS'],
    'execution.phases'    => ['VIEW_EXECUTIONS'],
    'execution.activity'  => ['VIEW_EXECUTIONS'],
    'execution.messages'  => ['VIEW_EXECUTION_METADATA'],
    'execution.detail'    => ['VIEW_EXECUTION_METADATA']
];
```

These are illustrative **consumer-defined requirements**, not package-managed vocabulary or automatic grants.
Instantiate Common's `SummarizeExecutionsTool`, `ListExecutionsTool`, `GetExecutionTool`, `ListExecutionPhasesTool`,
`ListPhaseActivityTool`, `ListCapturedMessagesTool` and `GetCapturedDetailTool` with the actual configured QueryBus;
retain their native schemas, mirrors and outputs. Do not copy Tools or wrap their schemas merely to add attributes.
A Tool can require several Permissions; every requirement is conjunctive. Equal canonical requirements within
one source are deduplicated in first-declaration order.

Exactly one source must own each Tool: valid nonempty `handle()` attributes **or** a valid nonempty map entry.
Both sources reject even with identical values; no override, union or rescue of malformed metadata is supported.
Absence of both sources also rejects: no implicit public Tool. Mapping names resolve exactly through Common's registry;
unknown names (including case/whitespace variations), integer keys, non-list/empty values, non-string elements
and malformed `PermissionName::fromString()` values reject the whole composition, even after valid entries.
No trimming, aliasing or second naming algorithm is applied. Configuration loaders must reject duplicate target
keys **before** PHP array construction or merging: overwritten keys cannot be recovered by this catalog.
Last-wins merging is not a supported configuration strategy. No configuration parser is supplied.

Common validates declarations, canonical names, duplicate identities and interactive metadata. The catalog enumerates
Common's **complete** definitions through its public `available()` seam using a construction-only include-all
predicate, finds each registered Tool by that resolved name, and reflects only `RequiresAgentPermission` on `handle()`
to select its single requirement source.
That predicate is private construction machinery, never a request availability policy. No second Tool list, naming
algorithm, permissive missing-attribute rule, filesystem scanner or metadata cache is introduced.

A missing requirement or any malformed requirement fails construction with Common `DomainException`; no partial catalog
is returned. Public Tools belong in a separate unprotected composition. Empty registries are valid and authorize nothing.
The final readonly catalog retains exact immutable Common definition objects and validated nonempty requirement lists.
Unknown names and foreign definitions (even an identical clone from another registry) return null. This prevents runtime
identity drift from borrowing a same-name requirement. Empty registry plus empty mappings authorizes nothing;
an unknown mapping still rejects. Do not
hydrate/serialize private catalog state or bypass its constructor.

Only static metadata may be reused across requests. Reuse a catalog with **the same registry instance**; rebuild both
together when composition changes. It retains no Tool instances, Agent principals or filtered discovery results.
Repeated lookups and availability checks perform no reflection. Consumer-held Tool instances must independently be
safe for their configured lifetime; static catalog reuse does not make mutable Tool collaborators request-safe.

## One composition per MCP request

The consumer maps transport data to `SignedAgentRequest`, creates a nonempty safe correlation ID, and constructs a fresh
`CurrentAgentPrincipalProvider` using authoritative Agent/Permission repositories, secret decipher, HMAC verifier,
clock, atomic nonce consumer and transactional Unit of Work. Do not put that provider or availability in a singleton.
Use one availability object for all Common paths **within that request**:

```php
use Fight\AccessControl\Adapter\Mcp\Tool\Agent\AgentToolAvailability;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;

$availability = new AgentToolAvailability($catalog, $provider, $signedRequest, $correlationId);
$discovery = new McpToolDiscovery($registry, $availability, $cursorKey, ttlMs: 0, cacheScope: 'private');
$invoker = new McpToolInvoker($registry, $availability, interaction: $interaction);
```

`$cursorKey` is a consumer-managed stable secret of at least 32 bytes, not an Agent credential. `$interaction` is null
for a noninteractive composition; interactive Tools require Common `McpToolInteraction` with a qualified state protector,
stable neutral caller binding and, when confirming, an atomic confirmation store. Register `$discovery` and
`new McpToolInvocation($invoker)` with the consumer's Common capability registry/responder. Common requires `tools/list`
when advertising Tools. Every exposed direct Tool, CLI, HTTP, broker or worker entry must establish its actual caller, applicable
operation Permission and target scope before configured dispatch. Attributes do not intercept `handle()`.
The raw in-process bus remains trusted infrastructure, not another authenticated public API: do not add blanket
duplicate bus/Application authentication. Owning Query admission still enforces scoped business policy.
Tool availability must not manufacture all-project, reference, metadata or body scope from caller selectors.
`execution.messages` and `execution.detail` can return permitted metadata with `body_permitted: false` and
`chunk: null`; do not require a body Permission merely to expose these Tools. TASK-00089/00090 own downstream
scoped-read integration, not this coarse capability grant.

TASK-00147's [six-declaration contract dispositions](agent-profile-tools.md#current-contract-dispositions-task-00147)
include this catalog, Attribute and availability plus the actual mixed execution Tool additions from TASK-00084.
Their construction/decision contracts remain unchanged. Dynamic configuration validation and the exact immutable
descriptor check are not Query result assertions; the profile caller uses TASK-00128's permitted known-result annotation.
Tool argument extraction independently validates its dynamic string before Domain construction. Neither annotation
nor validation authorizes direct handling.

The first recognized Tool decision lazily calls the provider once. It retains the complete immutable principal, or
retains unresolved status on **any** resolution failure, for that request only. It never retries authentication while
iterating Tools. Missing/foreign definitions deny without resolving authority. Every declared Permission must match;
no principal or error details go to Common. Provider resolution retains its existing nonce transaction and authority
fences; this integration dispatches no command, query or event and makes no additional authority write.

If transport authentication already resolved the **same request's** provider, availability reuses that snapshot.
Consumers requiring server-side authentication diagnostics can observe the provider's existing secret-free rejection
at their authentication boundary; lazy neutral availability intentionally exports no diagnostics or throwable.
A transport authentication failure is terminal for that request; never retry it independently through availability.
Do not log the signed request or secret-bearing provider dependencies. Transport authentication may reject before MCP
dispatch and is not promised to be indistinguishable from an MCP unknown Tool.

## Discovery, invocation and protected retries

- Discovery filters before Common's canonical ordering/pagination. Keep `ttlMs: 0`, `cacheScope: private`; never cache
  principals, decisions, filtered results or pages across requests. A later page is another MCP request and therefore
  needs fresh authority too; Common's cursor binds the resulting visible catalog, not authority.
- Direct invocation uses the identical availability before schema/Validation rules, Tool handling, CQRS dispatch,
  progress or protected diagnostic work. Common returns its own `Unknown or unavailable tool.` outcome for both denied
  and unknown names; AccessControl does not implement protocol errors.
- On retry, Common privately opens protected state only to locate the candidate Tool. **That is not disclosure or
  authorization.** A fresh availability/provider then checks current authority before state restoration, binding and
  input-response validation, confirmation consumption, `resume()`, buses or progress. Use a fresh signed request/nonce;
  neither the interaction token nor an earlier discovery result grants authority.
- Revocation, credential replacement and Permission changes before a later request affect that request. Changes after
  one request's principal resolution do not cancel work admitted under that snapshot. A denied snapshot also stays denied
  inside that request after a grant; the next request may succeed. No in-flight cancellation or cross-request cache exists.

## Evidence and limits

Default product tests exercise the real installed Common registry, discovery, invoker, interaction and responder:

| Evidence | Observable contract |
| --- | --- |
| `RequiresAgentPermissionTest` | Repeatable canonical values, no normalization, native wrong-target rejection; metadata alone does not enforce access. |
| `MixedAgentToolsTest` | All seven real Common execution Tools plus attributed profiles, owning Query dispatch and safe output including optional typed activity facts, metadata-only detail, profile write/Event effects, conjunctive denial, private discovery and later-page/request freshness with the real provider and controlled ports. |
| `AgentToolPermissionCatalogTest` | Complete explicit/attributed requirements, invalid mapping shapes/targets, equal/different dual-source rejection, deduplication,  repeated stable value identities, unknown/foreign definitions, duplicate and contradictory Common identity rejection, missing/malformed metadata, separate public composition. |
| `AgentToolAvailabilityTest` | Every missing-Permission permutation and unresolved authority, one successful/failed lazy resolution, operational/diagnostic failure concealment, no authority work for foreign definitions, private zero-TTL filtered pages, permitted validation/bus/progress and denial-before-work. |
| Same suite, authority-change cases | Actual provider and Domain successors with modeled committed repository snapshots: same-request continuation and later-request denial after revocation, rotation or Permission removal; newly granted authority/new credentials require fresh request scopes. |
| Same suite, interaction cases | Both attributed and explicitly mapped initial protected confirmation, successful fresh retry, and revocation/rotation/Permission-removal denial before valid or malformed retry restoration, response validation, store consumption, resume, buses and progress. Unknown/denied errors match and retained rule labels are not disclosed. |
| Existing Agent/provider/Permission/Security tests | Unchanged authentication, nonce/revision fences, direct authority and non-MCP behavior remain in the full gate. |

The HMAC verifier, repositories, buses, state protector and confirmation store are controlled package-port fixtures.
These tests do **not** qualify real cryptography, database concurrency, transport authentication, a deployed endpoint,
consumer container lifetimes or a real state/confirmation backend. Before adoption, qualify those exact compositions,
fresh request/job scope and alternate-path protections. No screenshots are useful for this library boundary; executable
MCP results, denial equality, nonce/validation/bus/progress counts and protected-state outcomes provide nonvisual proof.

TASK-00084's binding extension is qualified against the current minimum Common v1.5.0 and the ordinary dependency
lane; exact installed source/lock and command results belong in its ignored Work handoff. Controlled Query results
prove coarse Tool-to-Query integration, not actual consumer scoped repositories, protected-body admission or deployed
MCP transport. Independent Review/QA remain required before acceptance.

The original three APIs were additive. Their current Adapter relocation is a pre-v1 PHP namespace break, not a
Common API adoption or dependency change; the earlier `^1.2` → `^1.3` minimum change is release history. Existing AccessControl authentication,
Permission assignment and SecurityContext APIs/behavior are unchanged. No serialization/OpenAPI payload, persistence
migration, compatibility shim, release, consumer adoption or deployment is supplied by this TASK.
