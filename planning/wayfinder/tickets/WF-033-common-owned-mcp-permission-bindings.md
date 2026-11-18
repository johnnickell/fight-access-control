# Bind Common-owned MCP surfaces to Agent Permissions

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Later concrete APIs must match the selected Common contract; implementation and release scope remain separately authorized
**Map:** [Permission integration for evolving Common MCP capabilities](../mcp-capability-permission-integration-map.md)
**Depends on:** WF-032

## Question

How should AccessControl bind Agent Permissions to Common-owned MCP Tools and other neutral capability hooks,
without editing Common, duplicating its use cases, manufacturing consumer scope or freezing live authority?

## Must decide

- Support explicit registry-bound Permission requirements alongside attributes, or choose another deliberate
  composition such as attributed delegation wrappers. Define complete coverage, exact definition identity,
  conjunctive requirements and rejection of missing/malformed/conflicting/foreign mappings. Decide how attributes
  and explicit declarations coexist; no permissive fallback or silent override.
- Set the reusable integration boundary for exact Resources, whole-Skill entries and Prompts. Decide which
  family-specific bindings belong in this package versus consumer composition; no blanket all-capabilities grant.
- Keep coarse Tool/family permission separate from target-aware Common admissions. Decide whether reusable permission
  helpers plus consumer scope composition suffice; AccessControl must not acquire another application's project,
  session, content-retention or Task-sharing model by inference.
- Preserve the current short-request principal contract while defining trusted authoritative revalidation for
  later live disclosure/worker continuation. Distinguish original authentication, credential/principal binding,
  Permission/scope refresh, failure and revocation/rotation behavior; do not replay a consumed signed nonce.
- Keep human authority, Agent direct Permissions and OAuth mapping distinct, and identify required independent
  grants for safe metadata versus protected bodies and future operation-specific access.

## Required evidence

[WF-032 research](../research/fight-common-mcp-permission-integration.md), including the 7/7 catalog rejection
probe, current Common availability/admission signatures, exact-definition catalog behavior and current provider
snapshot/nonce semantics. During resolution inspect only concrete sources needed to compare the selected options.
Use Common's accepted future requirements to define freshness obligations, not to invent unreleased PHP interfaces.

## Accepted interview choices

### Tool binding: explicit mappings alongside attributes

John selected option A: explicit Permission mappings for Common-owned Tools, retaining attributes for Tools owned
by AccessControl or consumers. Do not require attributed delegation wrappers or modify Common's Tool classes.
Every protected Tool needs requirements, all declared Permissions are required, and missing/conflicting declarations
reject composition. Preserve the catalog's same-registry/exact-definition protection; no permissive fallback or
silent override. Exact public API and coexistence/conflict mechanics remain downstream design within these guarantees.

This accepts the Tool-binding direction only, not a Permission grant, implementation or release scope.

### Ownership: reusable permission-check tooling, consumer policy and scope

John agreed with the ownership split, clarifying that AccessControl provides permission-check tooling. Supply
reusable Agent Permission bindings for Tools, exact Resources, whole-Skill entries and Prompts; consumers configure
requirements/grants and decide application-specific project/session/document/Task relationships and scope. Common
retains protocol mechanics and enforcement through its neutral ports. This is integration tooling, not a new
application policy engine, relationship store or automatic grant system.

Tool/family availability does not imply all-project access, protected metadata or body permission. Keep those checks
separate and human administrator authority distinct from Agent grants; OAuth scopes are not Agent Permissions.
This accepts ownership, not a promise to ship every binding in the first release. WF-034 selects release tranches.

### Freshness: request-local admission and current live/continuation checks

John agreed with the freshness boundary. Ordinary requests retain their current request-local principal/Permission
snapshot. Before each new protected live disclosure or background continuation, recheck current Agent authority
(including status and original credential binding), applicable Permissions and consumer-owned current scope. Lost
authority, credential change or verification failure denies further protected disclosure/work; no silent credential
switching or repeated use of the original authentication nonce.

Consumers/Common runtime invoke these checks at their applicable boundaries. AccessControl supplies the check
contracts/tooling, not a stream manager, scheduler or job executor. Denial does not retract already disclosed bytes,
undo prior effects or prove that a durable job stopped. Exact APIs, synchronization/fence mechanics and recovery
composition remain design details to specify and prove under these guarantees; they cannot weaken the accepted
freshness boundary.

John's follow-up asks about framework-native middleware/subscriber/filter adapters. That separate ownership and
package-boundary choice belongs to [WF-035](WF-035-framework-permission-adapter-ownership.md), not an inferred
amendment of this decision or authorization to add an Adapter layer.

## Resolution boundary

May settle reusable integration ownership and authorization guarantees, with explicit exclusions. Does not create
an EPIC, select a version, change an approved Common requirement, add source or approve consumer grants. Release
tranches and EPIC destination approval belong to WF-034. This is an interview inside Wayfinder, not an automatic
invocation of the EPIC-producing Grill skill.

## Resolution

Closed after John's acceptance of explicit Tool mappings alongside attributes, reusable permission-check tooling
with consumer-owned policy/relationship scope, and the short-request/live freshness boundary above. The package
provides permission-check integration, not application policy, automatic grants or execution management.

No exact public API, Permission grant, implementation or release scope is accepted. Next resolve the explicitly
requested adapter ownership question in [WF-035](WF-035-framework-permission-adapter-ownership.md), then WF-034's
release tranches/prerequisites and approved EPIC destinations.
