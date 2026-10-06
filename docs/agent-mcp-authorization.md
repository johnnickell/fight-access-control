# Agent-protected MCP Tool composition

This unreleased additive API implements [TASK-00035](../planning/tasks/00035-TASK.md). It requires Fight Common
`^1.3` (first verified against v1.3.0, `7de6cad6e8a9752973ad9f8e27e285b0c1510582`). It supplies authorization
metadata and availability, **not** protocol policy, an endpoint, OAuth mapping or a consumer adapter.
[Self-service profile Tools](agent-profile-tools.md) are a separate consumer of this boundary. Their request-bound
instances require the same fresh provider/request as availability. Independent review/behavioral QA and actual
consumer qualification remain separate from builder tests.

## Public contracts

All three types live under `Fight\AccessControl\Application\AccessControl\Agent`:

| Type | Contract |
| --- | --- |
| `Attribute\RequiresAgentPermission` | Method-only, repeatable metadata. Constructor `string $permissionName`; `getPermissionName(): PermissionName` returns the existing validated canonical value. Every declaration is required. |
| `AgentToolPermissionCatalog` | Immutable static requirements for one `McpToolRegistry`. Constructor accepts that registry only. `requirementsFor(McpToolInfo): ?array` returns a nonempty list of Permission names or null for an absent/foreign definition. |
| `Security\AgentToolAvailability` | Request-scoped `McpToolAvailability`. Constructor takes the catalog, a fresh `CurrentAgentPrincipalProvider`, the request's `SignedAgentRequest`, and a nonempty correlation ID. `isAvailable(McpToolInfo): bool` is the only decision Common receives. |

Declare requirements on each Tool's `handle()` alongside Common's metadata, for example:

```php
#[McpToolInfo('reports.read', 'Read a report', ['type' => 'object'], ['type' => 'object'])]
#[RequiresAgentPermission('VIEW_REPORTS')]
#[RequiresAgentPermission('VIEW_AUDIT')]
public function handle(ApplicationData $input, McpProgressReporter $progress): McpToolOutput
```

This is a method declaration excerpt; the consumer implements its body and supplies the corresponding imports and
Common `McpTool` implementation. Metadata alone does not intercept calls to `handle()`; callers must use the
composed Common discovery/invoker path. Repeated equal requirements are harmless but do not introduce any-of semantics.
Permissions match existing canonical `PermissionName` values against direct Agent authority, never Roles or OAuth scopes.

## Static composition

Construct the registry once from the complete explicitly exposed Tool set, then construct its catalog:

```php
use Fight\AccessControl\Application\AccessControl\Agent\AgentToolPermissionCatalog;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;

$registry = new McpToolRegistry($tools);
$catalog = new AgentToolPermissionCatalog($registry);
```

Common validates declarations, canonical names, duplicate identities and interactive metadata. The catalog enumerates
Common's **complete** definitions through its public `available()` seam using a construction-only include-all
predicate, finds each registered Tool by that resolved name, and reflects only `RequiresAgentPermission` on `handle()`.
That predicate is private construction machinery, never a request availability policy. No second Tool list, naming
algorithm, permissive missing-attribute rule, filesystem scanner or metadata cache is introduced.

A missing requirement or any malformed requirement fails construction with Common `DomainException`; no partial catalog
is returned. Public Tools belong in a separate unprotected composition. Empty registries are valid and authorize nothing.
The final readonly catalog retains exact immutable Common definition objects and validated nonempty requirement lists.
Unknown names and foreign definitions (even an identical clone from another registry) return null. This prevents runtime
identity drift from borrowing a same-name requirement. Malformed lists have no supported construction path; do not
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
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentToolAvailability;
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
when advertising Tools. Protect every alternate direct invocation/bus path too; this package does not secure bypasses.

The first recognized Tool decision lazily calls the provider once. It retains the complete immutable principal, or
retains unresolved status on **any** resolution failure, for that request only. It never retries authentication while
iterating Tools. Missing/foreign definitions deny without resolving authority. Every declared Permission must match;
no principal or error details go to Common. Provider resolution retains its existing nonce transaction and authority
fences; this integration dispatches no command, query or event and makes no additional authority write.

If transport authentication already resolved the **same request's** provider, availability reuses that snapshot.
Consumers requiring server-side authentication diagnostics can observe the provider's existing secret-free rejection
at their authentication boundary; lazy neutral availability intentionally exports no diagnostics or throwable.
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
| `AgentToolPermissionCatalogTest` | Complete requirements, repeated stable value identities, unknown/foreign definitions, duplicate and contradictory Common identity rejection, missing/malformed metadata, separate public composition. |
| `AgentToolAvailabilityTest` | Every missing-Permission permutation and unresolved authority, one successful/failed lazy resolution, operational/diagnostic failure concealment, no authority work for foreign definitions, private zero-TTL filtered pages, permitted validation/bus/progress and denial-before-work. |
| Same suite, authority-change cases | Actual provider and Domain successors with modeled committed repository snapshots: same-request continuation and later-request denial after revocation, rotation or Permission removal; newly granted authority/new credentials require fresh request scopes. |
| Same suite, interaction cases | Initial protected confirmation, successful fresh retry, and revocation/rotation/Permission-removal denial before valid or malformed retry restoration, response validation, store consumption, resume, buses and progress. Unknown/denied errors match and retained rule labels are not disclosed. |
| Existing Agent/provider/Permission/Security tests | Unchanged authentication, nonce/revision fences, direct authority and non-MCP behavior remain in the full gate. |

The HMAC verifier, repositories, buses, state protector and confirmation store are controlled package-port fixtures.
These tests do **not** qualify real cryptography, database concurrency, transport authentication, a deployed endpoint,
consumer container lifetimes or a real state/confirmation backend. Before adoption, qualify those exact compositions,
fresh request/job scope and alternate-path protections. No screenshots are useful for this library boundary; executable
MCP results, denial equality, nonce/validation/bus/progress counts and protected-state outcomes provide nonvisual proof.

The three classes are additive public PHP APIs. Raising the Common minimum from `^1.2` to `^1.3` narrows dependency
compatibility and belongs in the next pre-v1 minor release, not a patch/backport. Existing AccessControl authentication,
Permission assignment and SecurityContext APIs/behavior are unchanged. No serialization/OpenAPI payload, persistence
migration, compatibility shim, release, consumer adoption or deployment is supplied by this TASK.
