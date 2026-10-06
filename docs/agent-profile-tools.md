# Self-service Agent profile Tools (unreleased)

[TASK-00071](../planning/tasks/00071-TASK.md) adds two noninteractive Application `Agent\Tool` classes using
Fight Common `^1.3`. They reuse the [minimal profile query](agent-profile.md), [generic rename](agent-name-updates.md)
and [Agent-aware authorization](agent-mcp-authorization.md); they do not implement a second protocol or rename path.

| Class | Canonical Common Tool name | Input | Required Permission |
| --- | --- | --- | --- |
| `GetAgentProfileTool` | `agent.profile.read` | Empty JSON object | `AGENT_PROFILE_READ` |
| `UpdateAgentProfileTool` | `agent.profile.update` | Object containing only string `name` | `AGENT_PROFILE_UPDATE` |

Both declarations reject additional properties. There is no caller-selected Agent ID, initiator, credential,
Permission or lifecycle field. Each successful output contains exactly `{agent_id, name}`, plus Common's matching
derived JSON text, not an authored success sentence. These are additive PHP/Tool contracts, with no existing API,
persisted contract or OpenAPI component change. They remain unreleased; no endpoint or consumer adapter is supplied.

## Compose one protected request

Use a fresh `CurrentAgentPrincipalProvider`, `SignedAgentRequest` and nonempty safe correlation ID for each MCP
request. Pass **that same provider, request and correlation** to both Tools and `AgentToolAvailability`. The provider
resolves lazily during availability and returns the same immutable principal during Tool execution, without a second
authentication/nonce transaction. Transport authentication may resolve that same provider first. Never reuse these
Tools, provider or availability across requests or jobs, even when the signed credential has not changed.

```php
use Fight\AccessControl\Application\AccessControl\Agent\AgentToolPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentToolAvailability;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\AgentProfileToolFailures;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\GetAgentProfileTool;
use Fight\AccessControl\Application\AccessControl\Agent\Tool\UpdateAgentProfileTool;
use Fight\Common\Application\Mcp\Tool\McpToolDiscovery;
use Fight\Common\Application\Mcp\Tool\McpToolInvocation;
use Fight\Common\Application\Mcp\Tool\McpToolInvoker;
use Fight\Common\Application\Mcp\Tool\McpToolRegistry;

// Request-scoped dependencies; $commands implements Common SynchronousCommandBus.
$tools = [
    new GetAgentProfileTool($provider, $signedRequest, $correlationId, $queries),
    new UpdateAgentProfileTool($provider, $signedRequest, $correlationId, $commands)
];
$registry = new McpToolRegistry($tools);
$catalog = new AgentToolPermissionCatalog($registry);
$availability = new AgentToolAvailability($catalog, $provider, $signedRequest, $correlationId);
$discovery = new McpToolDiscovery($registry, $availability, $cursorKey, ttlMs: 0, cacheScope: 'private');
$invoker = new McpToolInvoker($registry, $availability, failures: AgentProfileToolFailures::create());
$invocation = new McpToolInvocation($invoker);
```

Here `$queries` is Common `QueryBus`, registering `GetAgentProfile` with `GetAgentProfileHandler`; `$commands`
registers `UpdateAgent` with `UpdateAgentHandler`. The latter is explicitly `SynchronousCommandBus`, not an async
queue or a result-bearing bus. Handlers use consumer implementations of the existing Domain repositories and
transaction/clock/event contracts. The cursor key is consumer-managed, at least 32 bytes, not an Agent credential.
Register `$discovery` and `$invocation` with the consumer's Common capability registry and `McpResponder`.

This example rebuilds the registry/catalog with its request-bound Tools. A catalog binds exact Common definition
objects: **do not carry a catalog over to a new registry**, even if every name is identical. Reusing static metadata
in another composition does not permit retaining these request-bound Tools. Use Common's discovery/invocation paths;
calling `handle()` directly bypasses Permission enforcement. Metadata does not intercept direct calls.

The [authorization guide](agent-mcp-authorization.md) owns unavailable/unknown concealment, discovery, protected retries
and snapshot lifetime. Both profile Tools use that same neutral decision. Denied/unresolved Tools remain unknown or
unavailable before input validation, profile lookup, command dispatch, progress or protected diagnostics. The read
Permission does not grant update, nor does update grant read. A later request resolves current authority; an admitted
request retains its snapshot. Fresh profile reads and ACTIVE-state rename fences still apply after admission.

## Read and update outcomes

A read dispatches `GetAgentProfile` using only the authenticated principal's Agent ID. Every invocation fetches a
fresh minimal ACTIVE profile. Missing and revoked targets both produce the same safe unavailable failure. No
Permission lookup, name cache, mutation, transaction or event belongs to the read itself; request authentication
retains its existing separate nonce transaction. Repository faults are not absence or a successful cached read.

An update normalizes `name` through `AgentName::fromString()` (PHP `trim`, nonempty, at most 120 characters **after**
trimming). Common's input schema validates the string shape; the Domain value owns name semantics. Invalid names
return a safe Tool failure before dispatch or a write. Both the command's target and typed Agent initiator come only
from the authenticated principal, never input. Initiator identity records provenance, not authorization.

After successful void dispatch, the acknowledgement uses that target ID and normalized command input. Real changes
and valid no-ops have identical outputs. Only a real rename writes/advances the timestamp and publishes the existing
`AgentNameChanged` after commit. The Tool performs no compensating query and publishes no extra fact. If another
rename commits before this response arrives, this acknowledgement still describes the successful request, **not** the
latest name. Use the separately authorized read Tool for a fresh observation.

Any thrown dispatch failure prevents success output. `AgentProfileToolFailures::create()` supplies Common's exact-class
bindings, never throwable text:

| Failure class | Safe Tool message |
| --- | --- |
| `AgentNameException` | `The Agent name is invalid.` |
| `AgentUpdateException`, Common `LookupException` | `The Agent profile is unavailable.` |
| `AgentOperationRejectedException` | `The Agent profile operation could not be completed.` |

Expected mapped failures are Common `isError: true` Tool results without structured success content. They are distinct
from pre-execution unknown/unavailable concealment. Unmapped exceptions propagate through Common's diagnostic boundary;
`McpResponder::respond()` returns its generic `Internal error.` protocol response. If using lower-level `dispatch()` or
`invoke()` directly, provide an equivalent safe outer response boundary: never render or log arbitrary throwable text
or traces. Common's optional diagnostic sink receives wrapped original throwables; consumers must sanitize that sink
and the generic handler's `CommandFailedEvent` subscriber/log boundary too. The Tools do not alter handler event policy.

A publication fault **after commit** still reports failure while the rename remains persisted. Neither a mapped failure
nor a generic internal error asserts rollback, guarantees retry success or promises event delivery. Current-state
reconciliation requires fresh authorization. There is no outbox or reliable replay guarantee.

## Seed, grant and protect other paths

Use [the profile guide's stable-ID recipe](agent-profile.md#stable-consumer-owned-permission-identities): generate two
distinct `Uuid::comb()` IDs once during seed/migration authoring, fix them in version control and use them in the complete
managed policy. Never regenerate at startup or reconciliation. `AgentProfilePermissions::definitions()` creates two
managed `ADMIN_SAFE` definitions, not grants. Explicitly grant each needed Permission through consumer-authorized
assignment paths; listing or invoking a Tool cannot grant either one. Preserve all other managed policy definitions,
Roles and code references during reconciliation.

Consumers must protect every other generic `UpdateAgent` and `GetAgentProfile` entry point (HTTP, CLI, workers, direct
bus/repository access) with caller/target policy. These Tools expose self-service only; no administrator endpoint is
created. Consumers own transport authentication, exposed-tool selection, request lifetimes, TLS/limits/origins,
persistence and shared fences, event/diagnostic sanitization, seeding/grants and actual runtime composition.

## Evidence and limits

- `GetAgentProfileToolTest`: fresh principal-only query, exact minimal structured/derived-text output, absence/fault
  behavior and no read-side transaction, write, progress or event after authentication.
- `UpdateAgentProfileToolTest`: synchronous void dispatch with normalized input, principal-only target and typed
  initiator, invalid-name rejection, no Tool-authored event or success acknowledgement after failure.
- `AgentProfileToolsTest`: real Common registry/invoker/responder and real package read/rename handlers over controlled
  repositories. Covers independent Permissions and concealment, fresh next-request authority, extra-field rejection,
  real/no-op persistence and event differences, acknowledgement despite a later competing rename, missing/revoked
  targets after admission, rollback versus committed-publication failure, and constant public failure output.
- Existing TASK-00035 discovery/pagination/protected-retry conformance and TASK-00069 name-writer race tests remain
  the owners of those broader contracts; this slice does not duplicate them.

The TASK owns local gate receipts. These tests model committed state and controlled package ports, not actual HMAC
cryptography, transport authentication, database races, a consumer policy seed or an MCP server process. No screenshots
add useful proof at this library boundary: executable input/output and state/event evidence are used. Independent
technical review and behavioral QA remain separate from builder verification; release and adoption are not authorized.
