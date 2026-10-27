# Agent-protected MCP Skills

[TASK-00086](../planning/tasks/00086-TASK.md) composes whole-Skill Permissions with the independent
[Resource binding](agent-resource-authorization.md). This is unreleased package behavior within
[EPIC-00014](../planning/epics/00014-EPIC.md), not a hosted endpoint, content provider, activation runtime or release.
[Tool admission](agent-mcp-authorization.md) and Prompt admission do not grant Skill or member access.

## Validated immutable association

| Type in `Fight\AccessControl\Application\AccessControl\Agent` | Contract |
| --- | --- |
| `AgentSkillPermissionBinding` | Takes an actual validated Common `McpSkillRevision` and a nonempty list of canonical Permission-name strings. `revision()` and `permissions()` expose that association. Every requirement is conjunctive direct Agent authority. |
| `AgentSkillPermissionCatalog` | Takes a list of bindings; rejects non-bindings, duplicate/conflicting roots and overlapping exact member ownership. `revisions()` supplies the immutable snapshots to Common. `requirementsFor(StrictJson)` returns the bound requirements or null. Empty bindings grant nothing. |
| `Security\AgentSkillAvailability` | Implements Common `McpSkillAvailability`; takes the catalog, the request's trusted `CurrentAgentPrincipalProvider`, `SignedAgentRequest` and safe nonempty correlation ID. Shares that provider/request with Resource admission. |

Bindings accept no arbitrary entry array or inferred URI prefix: Common must already have validated the complete
revision. It owns frontmatter parsing, paths, member identities, finite construction bounds, bytes and digests.
Historical revisions require their own explicit bindings just like current revisions. A root URI is not a grant.
Rebuild catalog/provider/capability composition together when registrations change; do not replace an immutable
revision's bytes at the same address.

Runtime association checks the exact root and **complete deterministic `StrictJson::toString()` export** of the
bound revision's immutable entry. This preserves scalar types, float fractions, object/list distinctions,
frontmatter, manifest order, links, sizes and digests. Fresh objects or separately constructed revisions with the
same exact export work; Tool-definition object identity is not used. Reordered or changed exports, partial/extra
fields, foreign roots, missing/non-string identities and aliases deny. This is deliberately exact immutable-export
association, not general JSON semantic equivalence or a second Skill validator. It neither opens nor reparses files,
rehashes bytes, discovers storage nor constructs a second Resource registry.

## Trusted request composition

Consumers supply the validated revisions, parser/stream ports, keys, trusted authentication dependencies and
requirements/grants. For example (named collaborators below are consumer-owned):

```php
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentResourcePermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionBinding;
use Fight\AccessControl\Application\AccessControl\Agent\AgentSkillPermissionCatalog;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentResourceAvailability;
use Fight\AccessControl\Application\AccessControl\Agent\Security\AgentSkillAvailability;
use Fight\Common\Application\Mcp\Resource\McpResourceDiscovery;
use Fight\Common\Application\Mcp\Resource\McpResourceReadLimits;
use Fight\Common\Application\Mcp\Skill\McpSkillDiscovery;
use Fight\Common\Application\Mcp\Skill\McpSkillResources;

$skillCatalog = new AgentSkillPermissionCatalog([
    new AgentSkillPermissionBinding($currentRevision, ['VIEW_SKILLS', 'READ_SKILLS']),
    new AgentSkillPermissionBinding($historicalRevision, ['READ_SKILL_HISTORY'])
]);
$skillAvailability = new AgentSkillAvailability($skillCatalog, $principalProvider, $signedRequest, $correlationId);
$provider = new McpSkillResources($skillCatalog->revisions(), $skillAvailability, $streamFactory);

// Explicit exact Resource requirements remain independent; this example applies one requirement to every member.
$bindings = [];
foreach ($skillCatalog->revisions() as $revision) {
    foreach ($revision->resources() as $uri => $descriptor) {
        $bindings[] = new AgentResourcePermissionBinding($provider, $uri, ['READ_SKILL_FILES']);
    }
}
$resourceCatalog = new AgentResourcePermissionCatalog($bindings);
$resourceAvailability = new AgentResourceAvailability($resourceCatalog, $principalProvider, $signedRequest, $correlationId);
$resources = new McpResourceDiscovery(
    $resourceCatalog->providers(),
    $resourceAvailability,
    $cursorKey,
    'consumer-skills',
    ttlMs: 0,
    cacheScope: 'private',
    readLimits: new McpResourceReadLimits()
);
$skills = new McpSkillDiscovery($provider, $resources, $cursorKey, 'consumer-skills', ttlMs: 0, cacheScope: 'private');
// Register BOTH $skills and this exact $resources capability with Common's registry/responder.
```

Use exactly the Resource catalog's provider instances, including this same `McpSkillResources`. Common rejects
wrong/non-readable provider or shared-capability identity. Other explicitly registered Resource providers keep their
own requirements and scope; complete catalog collision/integrity checks include concealed entries. Static Permission
association does not allocate a content stream. `server/discover` advertises capability support, not protected entries.

### Whole entry versus selected member

- `skills/list` filters before pagination. `skills/get` admits an exact entry without requiring a previous list.
  Both require whole-Skill Permission **and every member's general Resource admission**. One denied or unmapped member
  conceals the entire entry; no redacted frontmatter or partial manifest is successful Skill discovery.
- `resources/list` and `resources/read` apply the provider-owned whole-Skill decision plus the **selected** Resource
  requirement. A generic Resource allow cannot bypass whole-Skill denial. Whole-Skill permission cannot bypass the
  selected member's denial. A permitted individual file needs neither prior list/get nor the other files' Resource
  Permissions. A complete-entry denial can therefore coexist with a permitted individual file read.
- Common selects exact registered members, rejects ownership conflicts and aliases, and opens only admitted content.
  Denied journeys allocate no stream. Its Skill provider also checks whole-Skill admission on direct `open()`;
  this is not a substitute for guarding alternate general Resource/provider/business Query entry points.
- Permitted text/binary bytes, MIME, URI, manifest sizes and SHA-256 digests remain Common's immutable values.
  Common retains finite scan/descriptor/raw/encoded budgets, complete-entry atomicity and stream cleanup.

## Authority, errors and consumer containment

Create fresh availability objects and a fresh trusted principal provider for **each ordinary request**, including
pages, saved links and historical reads. Share the exact provider, signed request and correlation context across
Skill/Resource/Tool checks and earlier successful authentication within that request. Successful resolution reuses
one immutable principal and one existing nonce transaction, not a signature/nonce per entry or member. Availability
retains its own failed resolution without retrying per item. Unknown/unmapped whole entries do not authenticate.

Later requests observe revocation, credential rotation and Permission removal/regrant. An already resolved short
request retains its documented snapshot; no live lease, in-flight interruption or cross-request decision cache is
created. Never reuse these request objects/providers in singletons, streams, jobs or another caller's context.
Cursors, digest integrity, immutable bytes, prior get, protocol `_meta`, OAuth scopes and human roles grant nothing.
Use private zero-TTL Skill **and Resource** results. Common binds continuation to the currently visible catalog and
scope; visible changes invalidate cursors, while concealed frontmatter does not enter visible cursor context.

Missing Permission/authentication rejection/current-authority rejection deliberately return unavailable. Unknown and
denied get/read share Common's invalid-params result without concealed names, manifests or links. Operational
resolution failure, invalid Permission snapshots, unexpected exceptions and provider/policy faults **propagate** to
Common's safe boundary; they do not become a fabricated empty/partial success. `McpResponder::respond()` reports
through its configured diagnostic owner and returns constant `-32603 / Internal error.` with no result. Direct
`dispatch()`/capability/provider calls need the consumer's equivalent safe boundary. Provider/policy protocol-error
injection is not public-error authority. No logger/renderer, credential export or new Domain event is installed.
Qualify diagnostic-sink redaction separately; do not publish raw private exceptions.

The consumer still owns bounded content acquisition, parser implementation, immutable publication/retention, actual
storage containment, trusted transport/authentication context, relationship/target scope, alternate entry points and
client/runtime lifetimes. Application-backed content retains independent business Query authorization under
[ADR 0010](../planning/adr/0010-permission-eligibility-and-caller-authorization.md). Instruction/script contents are
**untrusted data**: serving them neither executes/activates them, grants application scope nor authorizes any linked
Tool, Prompt, Resource, live disclosure or background continuation.

## Qualification boundary

The supported Common minimum remains **v1.3.0**, exact tagged source
`7de6cad6e8a9752973ad9f8e27e285b0c1510582`, within unchanged `^1.3`. Its Skill/Resource contracts are identical to
installed v1.4.0 (`87b9580c072f3149d574e09865daa79620c92118`) for the inspected paths. Actual isolated v1.3.0
composition, not just tag presence, qualifies this minimum. TASK evidence retains exact lock/source manifests,
commands, logs/exits and tested input mapping. No development snapshot or guessed future tag is required.

`AgentSkillPermissionCatalogTest` covers invalid requirements, malformed/foreign/inexact entries and conflicting
immutable association. `AgentSkillAvailabilityTest` composes real Common list/get/read/responder with actual
AccessControl principal/Permission resolution and Resource admission: all 32 Permission combinations, complete-entry
concealment, independent direct reads, exact bytes/integrity, zero denied allocations, nonce counts, historical
freshness, caller isolation, safe faults, ownership conflicts, private cursors and tight encoded pages.
Controlled repository, cryptographic, parser and stream ports prove **package composition**, not actual deployed
storage, YAML parser security, crypto, database races, transport containment or real-client behavior. Independent
technical Review and nonvisual behavioral QA remain required; screenshots add no useful library proof.
The combined EPIC release, all five frameworks and unassigned version remain unchanged.
