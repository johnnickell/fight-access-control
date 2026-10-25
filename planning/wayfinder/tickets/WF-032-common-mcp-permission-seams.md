# Establish Common MCP permission seams

**Labels:** `wayfinder:research`
**Mode:** AFK
**Status:** Closed
**Gate:** —
**Map:** [Permission integration for evolving Common MCP capabilities](../mcp-capability-permission-integration-map.md)
**Depends on:** —

## Question

Does current Common MCP code support permission enforcement, where does existing AccessControl integration stop,
and which remaining Common plans introduce additional authorization obligations?

## Must decide

- Distinguish available enforcement hooks from AccessControl binding gaps and still-unimplemented capabilities.
- Establish whether Common-owned Tools can use the existing protected Tool catalog unchanged.
- Identify scope/freshness and release prerequisites without selecting new product policy.

## Required evidence

Current Common source and selected tests; remaining execution-evolution planning; current AccessControl catalog,
principal/availability source; release-versus-working-tree identity and a bounded composition countercheck.

## Resolution boundary

Settle factual readiness and gaps only. This investigation cannot approve Permission names/grants, a new binding API,
release scope, Common amendments or implementation. WF-033 and WF-034 retain those decisions.

## Resolution

Completed 2026-10-25. The [research note](../research/fight-common-mcp-permission-integration.md) owns detailed
sources, maturity matrix, limitations and proposed integration path.

- Common supports neutral Tool availability, Resource/whole-Skill checks and separate scoped execution/detail Query
  admission. Its uncommitted Prompt implementation also checks list/get availability; it is not a released API.
- All seven Common execution Tools register with Common but fail AccessControl catalog construction because they
  lack `RequiresAgentPermission`; all are final. A research-only PHP probe confirms **7/7** expected rejections,
  **0 Query calls**. This is fail-closed integration incompatibility, not evidence of a bypass.
- AccessControl supplies no Resource, Skill, Prompt or execution-scope admission binding. Consumer implementations
  remain possible; Common does not need to depend on AccessControl.
- Planned Subscriptions/Tasks require distinct current permissions at operation/disclosure boundaries. The existing
  request-local authenticated principal is not a live revalidator, and nonce-consuming authentication cannot simply
  be replayed for every frame.
- Common HEAD `a38cf56c466e218ac56fbc07f12e4915c13c8a2f` contains unreleased execution Tools; Prompts were uncommitted
  on active TASK-00157. Subscriptions/Tasks remain approved planning. No current version/minimum or consumer
  qualification was inferred from those plans.

Existing suites were inspected, not rerun; the probe is not a canonical build or independent acceptance. No source,
Common records or dependencies were changed. Next decision:
[WF-033 — Bind Common-owned MCP surfaces to Agent Permissions](WF-033-common-owned-mcp-permission-bindings.md).
