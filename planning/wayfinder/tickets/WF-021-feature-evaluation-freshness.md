# Define Feature evaluation freshness

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Permission-based feature flags](../permission-based-feature-flags-map.md)
**Depends on:** [WF-017](WF-017-feature-availability-and-preview.md), [WF-018](WF-018-feature-preview-permission-binding.md), [WF-020](WF-020-feature-naming-and-management.md)

## Question

When must a Feature check observe a changed status or testing Permission, especially across requests and long-running
worker jobs?

## Must decide

- Settled: read current stored Feature status and testing Permission on each explicit evaluation, not a reused
  request/job-local Feature snapshot.
- Settled: no shared/cross-request Feature cache in the initial capability, and no retained Feature settings between jobs.
- Settled: switching OFF affects subsequent checks but does not interrupt work that already passed its check.
- Settled: use the existing authenticated User/Agent Permission snapshot, without a second authentication flow.

## Required evidence

- WF-017 establishes OFF/PREVIEW/ON availability but explicitly does not promise instantaneous cancellation of
  in-flight work. WF-020 settles lightweight management revisions, not runtime evaluation freshness.
- The [project context](../../../CONTEXT.md) describes request-scoped authenticated User/Agent authority. Inspected
  [CurrentPrincipalProvider](../../../src/Application/AccessControl/Authorization/Service/CurrentPrincipalProvider.php)
  resolves once per request; [SecurityContext](../../../src/Application/AccessControl/Authorization/Service/SecurityContext.php)
  delegates checks to the selected immutable authority. The
  [Agent provider](../../../src/Application/AccessControl/Agent/Security/CurrentAgentPrincipalProvider.php) likewise
  caches a complete immutable principal for one request. Source inspection, not executed behavioral verification.
- John agreed to fresh stored Feature state on every explicit check, no shared cache, no automatic interruption of
  already-admitted work, and reuse of the existing authenticated authority snapshot.
- Distinguish a second check in one request, the next request after a status/Permission change, consecutive jobs in
  one long-running worker process, and a job already admitted when the Feature becomes OFF.

## Considered options

- **Fresh state at each evaluation (accepted):** simplest freshness story without adding shared caching, with repeated reads when
  several checks run in one request.
- **Request/job-local Feature snapshot (not selected):** internally consistent evaluation with a bounded delay before changes appear; must
  define the boundary explicitly and prevent process-lifetime reuse by workers.
- **Cross-request caching (out of initial scope):** adds a staleness/invalidation contract without a demonstrated need.

## Resolution boundary

Set observable freshness and in-flight-work expectations, not a cache implementation, cancellation framework, new
principal model, or distributed coordination protocol. Keep the small-scope preference accepted in WF-020.

## Resolution

Closed. Every explicit Feature check reads the current stored Feature status and testing-Permission binding. A second
check in the same request is another evaluation, not reuse of a cached Feature row/result. Subsequent requests and
worker jobs likewise evaluate current Feature state; a long-running worker must not retain settings between jobs.

Use the existing authenticated principal snapshot for User/Agent Permission membership. Fresh Feature-state reads
do not claim per-check reauthentication or immediate refresh of grants/revocations within that snapshot's lifetime.
Consumers retain their existing authentication-scope obligations; this adds no process-lifetime principal cache.

OFF affects the next explicit check after the change is visible to the authoritative read. It does not retroactively
cancel work that already passed, schedule extra checks, or roll back earlier effects. A running job with no further
check can finish; if it explicitly checks again, that check observes current state. No shared Feature cache,
invalidation subsystem, or automatic cancellation machinery is required.

Implementation must preserve fresh-read semantics through consumer repositories, including avoiding stale ORM
identity-map results. Exact adapter isolation and read mechanics belong to implementation/conformance work, not a
new distributed coordination guarantee. This is accepted planning behavior, not implementation evidence.
