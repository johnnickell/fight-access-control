# Agent-protected MCP Resources

[TASK-00085](../planning/tasks/00085-TASK.md) adds Resource Permission composition under
`Fight\AccessControl\Application\AccessControl\Agent`. This is unreleased package behavior, not an endpoint,
content provider, consumer qualification or acceptance of the combined [EPIC-00014](../planning/epics/00014-EPIC.md).
[Whole-Skill authorization](agent-skill-authorization.md) composes this independent Resource decision in TASK-00086.
[Tool authorization](agent-mcp-authorization.md) is independent.

## Exact associations, not a discovered registry

| Type | Contract |
| --- | --- |
| `AgentResourcePermissionBinding` | Constructor takes a Common `McpReadableResourceProvider`, exact URI string and nonempty list of canonical Permission-name strings. Exposes `provider()`, `uri()` and validated `permissions()`. All requirements are conjunctive direct Agent Permissions. |
| `AgentResourcePermissionCatalog` | Constructor takes a list of bindings. Rejects invalid entries, repeated/conflicting URIs, missing ownership and non-exact lookup. `providers()` supplies the exact unique provider instances for Common; `requirementsFor(McpResourceInfo)` returns the bound requirements or null. |
| `Security\AgentResourceAvailability` | Implements Common `McpResourceAvailability`; takes the catalog, request's trusted `CurrentAgentPrincipalProvider`, `SignedAgentRequest` and safe nonempty correlation ID. Resolves authority lazily once for that short request. |

Example composition (consumer supplies the named dependencies and its real provider):

```php
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentResourceAvailability;
use Fight\Common\Application\Mcp\Resource\McpResourceDiscovery;
use Fight\Common\Application\Mcp\Resource\McpResourceReadLimits;

$catalog = new AgentResourcePermissionCatalog([
    new AgentResourcePermissionBinding($contentProvider, 'documents:handbook', ['VIEW_DOCUMENTS', 'READ_DOCUMENTS']),
    new AgentResourcePermissionBinding($contentProvider, 'documents:unlisted', ['READ_DOCUMENTS'])
]);
$availability = new AgentResourceAvailability($catalog, $principalProvider, $signedRequest, $correlationId);
$resources = new McpResourceDiscovery(
    $catalog->providers(),
    $availability,
    $cursorKey,
    'consumer-document-endpoint',
    ttlMs: 0,
    cacheScope: 'private',
    readLimits: new McpResourceReadLimits()
);
```

Register `$resources` with the consumer's Common capability registry/responder. Keep **exactly** `providers()` in
that composition; do not swap, add or wrap owners independently. The catalog retains provider instances, not content,
principals or a filtered catalog. Canonical `PermissionName` validation rejects a terminal newline as well as other
padding/malformed names; it does not normalize input. Their collaborators must be safe for the consumer's chosen lifetime. Rebuild static
bindings and capability composition together when registration changes. Public/unprotected content needs a separately
chosen composition, never a missing-requirement fallback. Empty bindings serve nothing.

Construction validates each explicit binding with metadata-only `find()`; it never enumerates `resources()` or opens
content. It does not freeze dynamic discovery. Unknown future entries are concealed until explicitly bound; an owned
URI need not be listed to be bound/read. Runtime decisions repeat metadata-only lookup through the **bound provider**
and require the exact URI and equivalent current descriptor values. Fresh `McpResourceInfo` instances, including fresh
nested JSON objects, work; neither Tool-definition object identity nor a historical descriptor snapshot is required.
Equivalence preserves scalar types and exact string values (`"01"` is not `"1"`, and a Boolean is not a link string),
JSON object/list distinctions and list positions. JSON object property order is irrelevant. No value is normalized.
A disappeared/reassigned identity or forged/stale non-equivalent metadata denies. No prefix, normalization, decoding,
filesystem/network resolution or caller-supplied ownership assertion is supported. These are trusted composition hooks,
not authentication of arbitrary caller-supplied descriptors.

Common still owns catalog scanning/order/pagination, collision detection (including concealed entries), exact lookup
across every configured provider, descriptor/encoded budgets, selected opening and stream consumption/cleanup. The
extra bound-owner metadata check cannot replace these protections. Provider `find()` must not read content; all
`resources()` and `find()` output must honor Common's descriptor/ownership contract. Only Common's admitted selected
provider opens bytes. Providers keep independent business Query admission and consistent metadata/content versions.

## Request authority and caching

Create fresh availability and principal-provider instances for **each** ordinary request, including later pages,
Tool/Prompt links, saved URIs and reads after discovery. Reuse the same trusted provider and signed request across
Tool/Resource decisions and any earlier authentication **within** that request. Successful provider resolution reuses
the immutable principal; Resource availability retains both success and failure without retrying nonce consumption
per descriptor. Unknown/unmapped metadata does not authenticate at all.

Every declared Permission must be in direct Agent authority. Roles, OAuth scopes, protocol `_meta`, links, cursor
possession and previously immutable bytes grant nothing. Later requests observe revocation, rotation and Permission
removal/regrant. A principal already resolved for a short request retains its documented snapshot; this is not live
authority, in-flight cancellation or permission to reuse a provider in a singleton/job/stream.

Use private zero-TTL discovery **and** read results. Do not cache principals, decisions, filtered catalogs, pages or
responses across requests/callers. Common's authenticated cursor binds visible metadata and endpoint scope, not an
Agent grant; changed visible authority invalidates a saved continuation rather than returning concealed entries.
`$cursorKey` is a consumer-owned stable secret (32–4096 bytes), not an Agent credential.

## Deliberate denial versus operational failure

- Unmapped/non-equivalent metadata, missing direct Permissions, authentication rejection and authority changes
  detected by the provider's request fence return unavailable. Common conceals denied direct reads with the same
  invalid-params result as unknown URIs, before opening. A deliberately empty permitted catalog is a valid success.
- `RESOLUTION_FAILED` and `PERMISSION_SNAPSHOT_INVALID` diagnostics are **faults**, not deliberate absence of a grant.
  Resource availability retains and rethrows the sanitized provider rejection. Unexpected failures likewise propagate;
  provider/lookup/policy errors are not swallowed into an empty/partial successful catalog.
- Use Common's real safe responder/transport boundary. `McpResponder::respond()` reports a fault to its configured
  diagnostic owner and emits constant `-32603 / Internal error.` without a result. `dispatch()` and direct capability
  calls propagate and require the consumer's equivalent safe boundary. Common wraps provider/policy protocol-error
  injection as an internal failure. Do not copy Tool availability's catch-all neutral-denial behavior here.
- This binding installs no logger/renderer and exports no signed request or credential. Diagnostic sinks and transport
  authentication remain consumer-owned; qualify redaction and avoid raw private exceptions in public responses/logs.

Permitted UTF-8 text and binary Base64 results preserve exact bytes and URI/MIME metadata. Common enforces finite raw,
descriptor, scan and encoded budgets, validates size/digest and closes returned streams on success/failure. Providers
own cleanup if opening throws, storage containment, bounded I/O/timeouts and content acquisition. Instructions/scripts
are untrusted data; serving them neither executes them nor authorizes Skill activation or any linked capability.
Consumer relationship/target scope and every alternate provider/Query/transport entry point remain separately guarded
under [ADR 0010](../planning/adr/0010-permission-eligibility-and-caller-authorization.md).

## Dependency and package evidence

The supported minimum remains Common **v1.3.0**, source `7de6cad6e8a9752973ad9f8e27e285b0c1510582`; no dependency
narrowing is needed beyond existing `^1.3`. Its tagged Resource contracts and actual list/read/responder behavior pass
the Resource composition and existing principal-provider tests in an isolated Composer-resolved scratch copy. The
ordinary installed lane uses v1.4.0, source `87b9580c072f3149d574e09865daa79620c92118`. Source/tag presence alone is
not that qualification; exact lock hashes, commands, complete logs/exits and candidate input mapping live in the TASK
handoff. The ignored lockfile remains an execution artifact, not a new tracked dependency contract.

`AgentResourcePermissionCatalogTest` covers static validation, empty/unknown/foreign mappings, dynamic fresh metadata,
disappearance, non-exact owner lookup, conflicting association and type-preserving nested descriptor equivalence.
`AgentResourceAvailabilityTest` also verifies real discovery conceals unequal numeric strings and typed nested links
while admitting fresh equivalent JSON. It composes the actual
Common responder/list/read with actual AccessControl principal resolution: all missing requirements, unlisted direct
reads, private pagination/cursors, request isolation, nonce counts, revocation/rotation/removal/regrant, concealed
collisions, normalization attempts, provider/policy/authority faults, inert exact bytes, bounds and stream closure.
Controlled repositories, cryptographic ports, content providers and PSR streams qualify **package composition**, not
a deployed database, actual cryptography, storage containment, transport/container lifetimes or client behavior.
Independent Review and nonvisual behavioral QA remain required; screenshots add no useful proof for this boundary.
No grants, storage/persisted shape, new business messages, adapters, release/version selection or deployment are added.
