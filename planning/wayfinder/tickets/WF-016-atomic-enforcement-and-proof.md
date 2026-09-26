# Set atomic enforcement and integration proof

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** John's decision after upstream and consumer adapter evidence.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-013, WF-014, WF-015

## Question

What transaction, locking/revision, audit, compatibility, and release evidence proves these decisions hold across
all supported command entry points?

## Must decide

- Which package-owned tier and policy/role/permission reads must be fenced through mutation, including no-op
  paths; what guarantees the package contracts require from each adapter. The application builder protects each
  command entry point under WF-013.
- Safe failure and audit outcomes: no partial write or success event on a package invariant failure, redacted
  failure evidence, and no credential/internal-state disclosure through HTTP.
- Package behavior/conformance matrix for custom-Role and direct Agent changes plus consumer PostgreSQL,
  authorization, HTTP, and cross-adapter tests;
  distinguish package gate from the later tagged release and consumer `./bin/build` adoption gate.
- Public API/version and consumer schema/adapter compatibility before a `v0.4.0` implementation handoff; do not
  invent a stored-Permission data migration for Fight Agent OS.

## Required evidence

- Inspect current Unit of Work, repository fences, handler event ordering, in-memory doubles, and the actual consumer
  adapters. Use race and stale-authority scenarios, not only direct handler unit tests.

## Resolution boundary

Set the implementation-ready proof and compatibility contract, then hand off to EPIC/TICKET/TASK planning in a
separate authorized step. Do not implement, tag, or update Agent OS dependencies here.

## Resolution

Open; no policy accepted.
