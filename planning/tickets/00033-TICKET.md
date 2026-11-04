---
id: TICKET-00033
epic: EPIC-00015
title: Clarify architecture and capability ownership before extending integrations
status: ready-for-agent
---

# Clarify architecture and capability ownership before extending integrations

## Problem and Outcome

Protocol presentation and Common registry/descriptor glue currently live beside Application policy, while broad
Agent Security/Service namespaces and abbreviated dependencies obscure responsibility. Deliver the accepted
[EPIC-00015](../epics/00015-EPIC.md) architecture through local rules, thin MCP adapters and cohesive Application
capabilities before adding more integrations. This is a pre-v1 PHP boundary replacement, not a behavior rewrite.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Maintainer places a type or collaborator | N/A; guidance and construction | One discoverable layer/ownership/naming rule with finite justified exceptions. |
| Integrator composes Agent profile Tools and family availability | Existing GetAgentProfile / UpdateAgent and AgentNameChanged | Canonical Adapter classes delegate through actual Common invocation/buses; existing admitted effects only. |
| Agent discovers, reads or invokes a protected capability | Existing family operations | Same identity-bound requirements, conjunctive permissions, safe output and request-local authentication. |
| Maintainer locates authentication/delivery/continuation or discovery collaborators | Existing Application use cases unchanged | Cohesive homes and descriptive names without a new service graph or workflow. |

## Requirements and Accepted Boundaries

- TASK-00107 codifies aggregate-first Domain/mirrored Application and capability/aggregate Adapter guidance, with
  PSR/upstream inward dependencies and narrow low-level/package exceptions. Reconcile WF-035/profile/ADR conflicts
  visibly; descriptive properties, parameters and locals identify repositories, buses and dispatchers.
- TASK-00108 moves concrete profile Tool schemas/input/output/failure mapping and family registry/descriptor/
  availability implementations into `Adapter/Mcp/{Tool,Resource,Skill}/Agent`. Keep real authentication, Permission
  policy and synchronized continuation in Application. No speculative Http/V1 namespace or protocol copy.
- TASK-00109 groups Agent authentication, provisioning, rotation/revocation, delivery, maintenance, continuation
  and Permission assignment by actual responsibilities; group Feature discovery and authorization admission too.
  Put the internal Permission-assignment coordinator outside CommandHandler. Avoid one-class folder inflation,
  generic helper buckets and redundant forwarding abstractions.
- Rename ambiguous dependencies throughout owned code, including named-argument callers and maintained examples;
  do not rename real collections mechanically. Move mirrored tests with their owning production boundary.
- Preserve direct use of existing Common use cases, request-local principal/nonce semantics, exact descriptor
  identity, independent business admission, exception policy, sensitive data boundaries and statement coverage.
  The intentionally different Tool versus Resource/Skill operational-failure policies remain distinct.
- Preserve native PSR-15/Symfony behavior and optional framework isolation. No persistence adapter or generic
  dependency-injection framework is introduced. Shared clocks/generators/ciphers keep explicit inward ownership.
- ADR 0011 permits direct current-contract changes, not AccessControl aliases or previous-snapshot support.

## Dependencies and Evidence

TASK-00108 and TASK-00109 depend on TASK-00107; their relative order is priority, not a technical edge. Existing
qualified Common APIs suffice for local relocation: this ticket must not wait for future canonical Common releases.
[TICKET-00034](00034-TICKET.md) separately owns that adoption. Native TASK-00105/00106 need neither MCP relocation
nor later authentication/operation refactors; their own current-contract tests remain authoritative.

## Acceptance Evidence

- [ ] Guidance and explicit amendments distinguish accepted target, present source and historical exceptions.
- [ ] Real Common profile/family journeys retain IDs, schemas, complete result shapes, exact identity and denial
  before validation/provider/bus/progress work; direct/alternate consumer entry points remain independently protected.
- [ ] Sequential/nested request and failure cases preserve scoped authority, nonce consumption and safe diagnostics.
- [ ] Canonical namespaces, descriptive names, autoload/static boundaries, moved tests and current documentation
  agree without compatibility aliases, copied use cases or an extra setup protocol for callers.
- [ ] Each executable slice passes focused behavior checks, canonical build/exact coverage and independent
  Review/applicable QA. The guidance-only slice uses appropriate documentation checks, not product tests of text.

## Exclusions

New permissions/grants, protocol/server ownership, new authentication behavior, persistence/runtime adapters,
Common changes, consumer migration/deployment and source repair through planning edits. User revision/authentication
and operation-boundary redesign belong to TICKET-00035/00036.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 10 | [TASK-00107](../tasks/00107-TASK.md) | Codify aggregate-first architecture and descriptive dependency naming | in-progress |
| 11 | [TASK-00108](../tasks/00108-TASK.md) | Move Agent MCP presentation and family bindings into adapters | ready-for-agent |
| 12 | [TASK-00109](../tasks/00109-TASK.md) | Organize Application capabilities and descriptive collaborator names | ready-for-agent |
## Decisions and Progress

John approved this requirement and the full three-TASK split: TASK-00107 rules (order 10), TASK-00108 MCP ownership
(11) and TASK-00109 capability/naming cohesion (12). Put these before the existing feature frontier without
inventing a dependency between independent relocations. Planning only; no implementation or acceptance is claimed.

Next: `/skill:next` for the Board's first executable TASK; execution is not launched by this record.
