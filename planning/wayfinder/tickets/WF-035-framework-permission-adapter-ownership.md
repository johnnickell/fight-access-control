# Decide optional framework permission adapters and safe-error composition

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** Actual framework/Common versions and supported lifecycles need later qualification; release scope and implementation remain separately authorized
**Map:** [Permission integration for evolving Common MCP capabilities](../mcp-capability-permission-integration-map.md)
**Depends on:** WF-033

## Question

Should reusable permission-check tooling include optional framework-native HTTP adapters, and where should those
adapters live while reusing Common's safe-error approach rather than creating competing handlers?

## Why this decision exists

After approving WF-033's freshness boundary, John asked whether it fits Agent OS middleware and whether to provide
Symfony subscribers, PSR middleware, Laravel adapters and the equivalent Yii/CodeIgniter hooks. He additionally
required compatibility with, and consideration of adoption of, Common's safe-error handling. This is new explicit
adapter planning, not permission to implement adapters or silently change the package's production boundary.

## Must decide

- Record the approved opt-in `src/Adapter` boundary in AccessControl, with capability-first directories modeled on
  Common's current adapter conventions; retain Domain/Application's existing DDD organization.
- Define one reusable permission-check contract; adapt request/route metadata, trusted authentication context and
  request-local lifetime without owning application grants/scope or duplicating business authorization.
- Prefer PSR-15 reuse for Slim and supported Yii 3; use Symfony's appropriate kernel event, native Laravel middleware
  and CodeIgniter 4 before filters where real translation is needed. Define supported framework generations and
  extension points before adding classes; equal adapter counts are not an outcome.
- Keep checks before protected validation/dispatch and, where promised, controller construction. Symfony
  kernel.controller already follows controller resolution; choose/qualify earlier post-routing admission or narrow
  the promise explicitly. Protect malformed/unclassified route declarations and main/subrequests deliberately.
- Establish compatibility with Common's safe-error contracts and how to reuse them. Permission adapters should
  signal explicit failures, not copy exception messages into bespoke responses or add another logger/renderer.
  Common's safe Application categories and HTTP policy own their declared mapping; consumer-specific outcomes need
  explicit classification, not message/code guessing or a broad conversion of operational faults into denials.
- Keep ordinary HTTP safe JSend presentation separate from MCP JSON-RPC/Tool failure mappings and OAuth responses.
  One redaction-aware reporting owner, deliberate HTTP challenge/header policy and no unsafe legacy-handler fallback.
- Select framework priorities and package-versus-real-consumer acceptance boundaries. Scope/version selection remains
  with WF-034 after ownership is settled; no all-framework first-release commitment is inferred.

## Required evidence

[Middleware and safe-error follow-up](../research/fight-common-mcp-permission-integration.md#follow-up--agent-os-middleware-framework-hooks-and-safe-errors)
records current Agent OS PSR-15 security/route wiring and failure mapping, Common's safe-error APIs and framework
hook evidence. The existing request-local middleware is compatible in architecture, but it does not qualify live
Agent revalidation or constitute a drop-in migration to Common's safe error handlers.

Common's safe HTTP/Application failure baseline is already present in tagged v1.4.0; do not misclassify it as solely
future work. Reinspect any next-version changes before adoption. Current AccessControl ^1.3 still permits versions
without that baseline; any slice using it must specify and test the actual minimum.

## Resolution boundary

Settles adapter ownership, structural placement and the approved package-boundary amendment. Does not edit source,
Common/Agent OS/starter records, install framework dependencies, select release scope, grant permissions, create
EPICs or authorize implementation/publication. WF-033's neutral tooling, consumer scope and freshness guarantees
remain unchanged.

## Resolution

John selected **`src/Adapter` inside AccessControl**, explicitly requiring Common-style **capability-first**
organization at that layer, not the DDD directory structure used in Domain/Application. This approves the narrow
production-boundary amendment for reusable permission/framework integration; no companion package is selected.
The [project profile](../../agents/project-profile.md#package-boundary-and-contracts) records the current rule.

### Placement and ownership

- Preserve dependency direction `Adapter → Application → Domain`; no framework/Adapter dependencies inward.
- Follow Common's current [ADR 0023](https://github.com/johnnickell/fight-common/blob/ccdb233f35f22745e7aff8a48bc4e320334a1022/planning/adr/0023-service-container-and-framework-adapter-namespaces.md):
  runtime adapters use `Adapter/<Capability>/<Framework-or-Provider-or-Standard>/...`; service-container extension
  points use `Adapter/ServiceContainer/<Framework>/...`. Do not copy Common's superseded alias namespaces or its
  1.x compatibility machinery into this pre-v1 package.
- Illustrative destinations, not directories to create now: `Middleware/Psr15`, `Middleware/Laravel`,
  `Http/Symfony/EventSubscriber`, `Http/CodeIgniter` and `ServiceContainer/<Framework>`, all under `src/Adapter`.
  Reuse PSR-15 for Slim/Yii 3 where actual contracts permit; no framework-specific duplicate just for symmetry.
- Keep adapters thin and explicitly enabled, with optional/isolated framework dependencies and one reusable
  permission-check policy inward. Consumers retain authentication selection, grants, target scope, bootstrap,
  routes, keys and runtime. Persistence adapters or unrelated infrastructure are not approved by this exception.
- Domain/Application retain existing DDD ownership and paths. Mirrored Adapter tests, autoloading, dependency rules,
  applicable runtime suites and exact owned-statement coverage must be wired by later approved implementation;
  no existing gate is weakened or claimed already extended.

### Error composition and qualification

Compatibility with Common's safe-error approach is required. Reuse its selected safe HTTP categories/presentation
boundary where appropriate rather than duplicating raw-message handlers, loggers or renderers. Preserve deliberate
consumer classification, required headers and one redaction-aware reporting owner; no unsafe legacy fallback.
MCP and OAuth retain their own protocol errors/concealment rather than inheriting JSend envelopes. Exact exception
translation and adoption of newer Common changes remain downstream design/qualification, not an Agent OS migration.

Required later proof covers framework-native ordering, request-local lifetime, declared-route handling, trusted
context, current checks for protected live/continuation work, redaction, headers, failure fallback and single
reporting. Qualify actual selected framework versions; Symfony kernel.controller does not establish denial before
controller construction. Do not infer real-consumer support, a blanket all-framework release, or adapter code from
this ownership decision. WF-034 owns priorities, release tranches/minimums and approved EPIC destinations.

**Next:** `/skill:wayfinder work WF-034`.
