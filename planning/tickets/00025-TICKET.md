---
id: TICKET-00025
epic: EPIC-00014
title: Bind Common-owned and attributed Tools to Agent Permissions
status: ready-for-agent
---

# Bind Common-owned and attributed Tools to Agent Permissions

## Problem and Outcome

The existing Agent Tool catalog requires `RequiresAgentPermission` attributes on every protected Tool. Common's
seven final execution Tools cannot carry AccessControl attributes or be subclassed to add them. Consumers need
explicit requirements for those Tools without copying their schemas, weakening missing-metadata rejection or
creating a reverse Common dependency.

Extend the current protected composition to accept explicit Tool Permission mappings alongside attributes, using
one complete Common registry and its exact immutable definitions. Every declared requirement remains conjunctive.
This extends the completed [TICKET-00006](00006-TICKET.md) contract under
[EPIC-00014](../epics/00014-EPIC.md); it does not reopen its historical acceptance or retain a retired API for
compatibility. [WF-033](../wayfinder/tickets/WF-033-common-owned-mcp-permission-bindings.md) owns the accepted direction.

## Use Cases

| Actor and trigger | Commands / Queries / Events | Observable outcome and side effects |
| --- | --- | --- |
| Integrator registers Common-owned and attributed Tools | N/A; static composition | Build complete immutable requirements against the same Common registry; reject missing, malformed or conflicting declarations before serving protected work. |
| Agent requests Tool discovery | No new business message | Current request authority filters before Common ordering/pagination; no cross-request principal or filtered-catalog cache. |
| Agent directly invokes a Tool | The selected Tool may dispatch its existing use case only after admission | Check every required Permission before selected-Tool validation, handling, bus work, progress or protected output. |
| Agent retries an interaction in a later request | No new checking message; Common owns retry/confirmation mechanics | Fresh request admission precedes restored-state disclosure, response validation, confirmation consumption and resume. |
| A foreign definition, unknown mapping or unavailable Agent reaches admission | N/A | Fail closed; no same-name authority borrowing or protected work. Common retains unknown/unavailable concealment. |

The binding itself dispatches no new Command, Query or Event and does not create grants. Existing authentication
nonce consumption remains a distinct, request-scoped side effect of the current authentication contract.

## Requirements, Validation and Permissions

- Support explicit mappings and existing attributes in the current chosen contract. Specify coexistence and
  conflict mechanics downstream without silently overriding declarations or introducing an implicit public Tool.
- Every Tool in a protected composition has a nonempty validated requirement set; all required canonical
  `PermissionName` values must be present in direct Agent authority. Missing/malformed/conflicting composition rejects.
- Preserve complete registry coverage and same-registry/exact-definition identity, not merely string-name equality.
  Unknown/foreign runtime definitions deny. Public/unprotected composition remains explicitly separate.
- Common owns canonical naming, metadata/schema validation, mirrors, discovery, invocation and protocol errors.
  Do not maintain a competing Tool list/naming algorithm or modify Common's final classes.
- Include all seven execution Tools (`execution.summarize`, `execution.list`, `execution.get`, `execution.phases`,
  `execution.activity`, `execution.messages`, `execution.detail`) alongside attributed profile Tools.
- Retain ordinary request-local principal semantics, fresh later requests and private zero-TTL discovery posture.
  Reuse the same trusted provider within one request rather than consuming the nonce for every Tool check.
- Availability is not target/data permission. Scope and protected-body composition belong to TICKET-00029; direct
  Tool/bus entry points outside the composed invoker require their own consumer protection.
- Common receives only its neutral decision, never an Agent principal, Permission reasoning or credentials.
  Preserve protocol-specific concealment and safe diagnostics; do not publish attribute errors or exception text.

## Dependencies and External Gates

- Reuse existing authenticated authority and Tool contracts; TICKET-00026/00027 are not prerequisites.
- [TICKET-00029](00029-TICKET.md) consumes this binding for the complete scoped execution-Tool journey.
  [TICKET-00031](00031-TICKET.md) uses it for applicable Tool-start paths, not as a substitute for Task operation checks.
- Inspect actual accepted/tagged Common definitions, invocation/retry ordering and execution Tool APIs before affected
  adoption. Establish/test the minimum Common version; the existing `^1.3` constraint is not proof of new classes.
- Keep Domain/Application ownership, ADR 0011's current-contract policy and the EPIC's combined-release gate.
  Exact API names, implementation slicing and dependency wiring are downstream design, not invented here.

## Acceptance Evidence

- [ ] Real mixed Common-owned/attributed Tool registry composes with complete explicit requirements and no wrappers
  copying Tool schemas; each missing, malformed, conflicting and foreign-definition case fails as specified.
- [ ] Every missing-Permission permutation denies before selected validation, Tool/Query/Command work, progress or
  protected retry restoration/consumption; unavailable and unknown public outcomes remain equivalent.
- [ ] Permitted discovery/invocation/retry uses the real Common paths; private paging/cursors do not cache authority.
- [ ] Actual AccessControl provider resolution is request-local; later revocation/rotation/Permission changes affect
  later requests, while already admitted short-request snapshots retain their documented semantics.
- [ ] Existing profile Tool behavior, minimum-version composition, safe failures and unchanged alternate-path
  obligations are covered with focused tests, documentation, exact coverage and the canonical package gate.
- [ ] Later implementation receives required independent technical Review and behavioral QA; no acceptance is
  inferred from this planning approval or the earlier research-only rejection probe.

## Exclusions

Target relationship policy, execution/body grants, Resource/Skill/Prompt bindings, live authority renewal, framework
middleware, automatic grants, copied Common Tools, a new endpoint/CQRS bridge or consumer adoption/deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 84 | [TASK-00084](../tasks/00084-TASK.md) | Protect mixed Common-owned and attributed MCP Tools | needs-info |
## Decisions and Progress

John approved this requirement area as item 1 of EPIC-00014's complete eight-TICKET split, then explicitly selected
this TICKET for To Tasks and approved its single-TASK plan. The complete accepted decomposition is now written as
[TASK-00084 — Protect mixed Common-owned and attributed MCP Tools](../tasks/00084-TASK.md). It owns the catalog
change and complete discovery/invocation/retry, dependency-minimum, test and documentation evidence together;
there is no separate prefactor or testing-only TASK.

TASK-00084 is `needs-info` pending actual qualified/tagged Common execution APIs and minimum-version evidence,
with no unfinished local TASK blockers. Requirement/TASK-plan acceptance is not implementation acceptance or
execution authority. No source, dependency adoption, grant or publication is authorized or performed.

This TICKET's TASK planning is complete. TICKET-00026's subsequently approved TASK-00085/00086 plan is also
written, as are TICKET-00027/00028's approved TASK-00087/00088 plans. TICKET-00029's approved TASK-00089/00090 plan is also written; TICKET-00030's approved TASK-00091–00096 plan is now written; TICKET-00031's TASK-00097–00102 and TICKET-00032's approved TASK-00103–00106 plans are written.
All EPIC-00014 map planning phases/full TASK plans are accepted/complete; consult the Board for execution selection.
Keep the map Closed and continue the remaining planning phase rather than starting implementation.

Next: `/skill:next` for Board-based selection; recommendation does not launch execution.
