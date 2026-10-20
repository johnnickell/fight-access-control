---
id: TICKET-00024
epic: EPIC-00001
title: Fence custom Role rename and removal with caller revisions
status: ready-for-agent
---

# Fence custom Role rename and removal with caller revisions

## Problem and Outcome

Provide package-owned caller concurrency for custom Role rename/remove: consumers submit the revision of the Role
state displayed to the operator, and the package admits only a matching current state. This is an approved new
capability under [WF-031](../wayfinder/tickets/WF-031-role-caller-concurrency.md), not a released-version assessment.

## Use Cases

| Actor and trigger | Commands/queries | Events and observable outcome |
|---|---|---|
| An authorized consumer displays a Role | Existing detail/list queries | Read-only view includes authoritative revision; no event |
| An operator submits a rename or confirmed removal | Rename/remove with required expected revision | Valid current intent may commit with the existing success event; stale intent yields a safe typed conflict |
| A Role writer changes state | Existing lifecycle, membership and managed-policy operations | Real changes participate in the revision contract; valid no-ops retain existing semantics |
| Registration resolves configured Roles | Existing registration flow | Complete expected-Role comparison and atomic registration outcomes remain intact |

## Requirements

- **R1 — Public contracts:** Expose authoritative revision in detail/list Views and require it in rename/remove
  constructors, accessors and canonical serialization. Validate missing/malformed input without fallback to current
  storage. Update current OpenAPI components under [ADR 0008](../adr/0008-openapi-payload-contract.md).
- **R2 — Persisted state:** Use a validated non-repeating concurrency identity across actual name/membership changes
  and identity recreation. The accepted preference is incarnation plus positive monotonic revision; an equivalent
  token must prove the same properties. Preserve it through hydration/restart and define exhaustion safely.
- **R3 — Admission:** Check expected revision before no-op admission and retain final atomic expected-state and
  reference guards. Fresh same-name no-ops do not write, advance state or publish success. All actual Role writers
  participate, including managed reconciliation and membership; registration comparisons include revision state.
- **R4 — Boundaries:** Preserve name uniqueness, managed/reserved identity, membership tiers, purpose-specific events,
  transaction/audit rules and existing membership retry behavior. Follow TASK-00077's operation-outcome contract.
  A revision grants no authorization. Consumers own permissions, confirmation, response mapping and actual adapters
  under [ADR 0010](../adr/0010-permission-eligibility-and-caller-authorization.md).

## Acceptance Evidence

- [ ] **A1:** Detail/list and command DTO/schema round trips agree; invalid current input/hydration rejects.
- [ ] **A2:** Fresh requests succeed; stale-before-dispatch and during-operation changes reject atomically.
- [ ] **A3:** Stale same-name requests reject; valid same-name and membership no-ops do not write or publish success.
- [ ] **A4:** Same-clock changes, name/membership ABA and same-ID recreation retain non-repeating identity; hydration,
      restart and exhaustion preserve the contract.
- [ ] **A5:** All writers and registration reference comparisons participate without changing unrelated command inputs.
- [ ] **A6:** Managed/reserved identity, name, tier and final reference rules hold under both concurrent winner orders.
- [ ] **A7:** Persisted rollback/post-commit outcomes and operation notifications satisfy the approved outcome contract.
- [ ] **A8:** Consumer-bindable scenarios and current-contract documentation cover persistence, token propagation and
      refresh/reconfirmation after conflict. No automatic substitution of a newer token for stale intent.
- [ ] **A9:** Focused/full gates, exact coverage, independent Review and nonvisual adversarial QA pass for implementation.

## Dependencies, Exclusions and Handoff

[TASK-00080](../tasks/00080-TASK.md) owns the accepted single vertical capability slice, blocked by
[TASK-00077](../tasks/00077-TASK.md) and [TASK-00079](../tasks/00079-TASK.md). The latter is shared Role/policy
integration sequencing. TASK-00078 has higher batch priority but is not a functional blocker.

Preserve [TICKET-00004](00004-TICKET.md)'s membership retries, [TICKET-00011](00011-TICKET.md)'s consumer/identity
boundary and [TICKET-00022](00022-TICKET.md)'s registration guarantees. Follow
[ADR 0011](../adr/0011-pre-v1-current-contract-only.md): no compatibility defaults/readers, backfill framework,
generic revision subsystem, production adapters or consumer edits. Consumers qualify their actual database,
transport/UI, permissions and runtime independently.

All batch planning phases are accepted; the [map](../wayfinder/access-control-correction-batch-map.md) is Closed
for planning only. This TICKET remains unfinished. Next: `/skill:next` for Board-based selection. Implementation,
publication of restricted material, release and deployment require their own authority.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 80 | [TASK-00080](../tasks/00080-TASK.md) | Fence custom Role rename and removal with caller revisions | ready-for-agent |
