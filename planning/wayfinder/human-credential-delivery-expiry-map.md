# Wayfinder Map: Human credential-delivery expiry cleanup

**Label:** `wayfinder:map`
**Status:** Closed

> This map is an **index, not a store**. The bounded contract decision lives in its linked decision ticket.

## Destination

Produce an approved, repository-native plan for package-owned discovery and terminalization of expired human-user
credential deliveries before `v0.5.0` readiness. A restarted consumer runner must need only package queries and direct
commands, not credential decryption, a provider call, or a copied lifecycle policy.

**Done** = the linked decision is closed, fog is resolved or excluded, and the map links to its approved planning
handoff. Implementation acceptance, independent review/QA, consumer qualification and release remain later gates.

## Notes

- John supplied the required behavior and verification scope. [WF-025](tickets/WF-025-human-delivery-expiry-contract.md)
  retains those constraints, current-code evidence and the approved complete family treatment.
- Fight Agent OS `TASK-00024` is an external consumer integration blocker, **not** an AccessControl TASK identity or
  local `blocked_by` edge. No Agent OS checkout, Composer lock or dependency order may be changed by this work.
- Current source baseline: `develop` `7ca8eecfa3a8ecc6d32b7cbe6f5502bde4e9fb2b`. Existing and concurrently changing
  Agent-profile planning edits are unrelated; preserve them. This map authorizes no implementation checkout/branch.
- [Current delivery contract](../../docs/credential-delivery.md), [ADR 0011](../adr/0011-pre-v1-current-contract-only.md),
  and completed [TICKET-00007](../tickets/00007-TICKET.md) are orientation/contract evidence. Do not reopen its Closed
  [original map](recoverable-credential-delivery-map.md) or completed parents merely to plan this follow-up.
- This capability is an explicit [v0.5.0 readiness prerequisite](../ROADMAP.md#human-delivery-expiry-readiness-prerequisite).
  Earlier Agent-operation acceptance does not establish this cleanup capability or consumer adoption.

## Decisions so far

1. **[Approve the shared human-delivery expiry contract — WF-025](tickets/WF-025-human-delivery-expiry-contract.md) is settled.**
   Invitation/reset use delivery expiry; email change uses full authority/reservation expiry, including already
   ciphertext-free deliveries and Users whose account state changed. The decision owns the complete state/race matrix.

## Decisions

| Decision ID | Title | Type | Mode | Status | Depends on |
|---|---|---|---|---|---|
| WF-025 | [Approve the shared human-delivery expiry contract](tickets/WF-025-human-delivery-expiry-contract.md) | Grilling | HITL | **Closed** | — |

## Blocking relationships

```text
WF-025 resolved → bounded EPIC → accepted TICKET → accepted TASK
→ checkout/worktree choice → implementation + focused/full gates → independent review + applicable QA
```

## Frontier

No Wayfinder decision remains. The only approved destination is written as [EPIC-00012](../epics/00012-EPIC.md);
this map's decision/EPIC phase is complete. John approved its sole requirement area, now written as
[TICKET-00020](../tickets/00020-TICKET.md), followed by its approved single implementation slice,
[TASK-00072](../tasks/00072-TASK.md). All EPIC/TICKET/TASK planning phases are accepted and complete. Consult the Board
for execution selection; implementation authority and placement remain separate. Map closure claims neither
implementation acceptance nor consumer qualification.

## Not yet specified (fog)

No additional product-policy uncertainty is currently known. Exact PHP names, serialization and schema shapes are
constrained by WF-025 and owned by TASK-00072's bounded design; record its concrete current contracts in that TASK.
New ambiguity found during design must return for a decision rather than silently broaden this scope.

## Out of scope

- Production persistence adapters, SQL/schema implementation, another queue, consumer retry/terminalization policy,
  cryptographic key operations, provider integrations and consumer product/email-change wiring.
- Agent OS changes, Composer-lock updates, dependency reordering or claims that its TASK/adoption is complete.
- Legacy bridges, old-release backports, historical readers/migrations/backfills, destructive resets or release tooling.
- Tagging, signing, pushing, PR publication, merging, package publication and deployment without separate authority.

## Planning and delivery handoff

### Approved EPIC handoff

**Destination title:** Package-Owned Human Credential Expiry Cleanup for v0.5.0

**Status:** Written and approved — [EPIC-00012 — Package-Owned Human Credential Expiry Cleanup for v0.5.0](../epics/00012-EPIC.md).
No other EPIC destination belongs to this map.

John confirmed the final EPIC boundary after WF-025 settled the family policy. The normal grill handoff wrote this
single EPIC and closed the map. John subsequently approved one cohesive requirement area for the complete recovery
journey, now written as [TICKET-00020 — Clean up expired human credential work after downtime](../tickets/00020-TICKET.md).
John then approved one complete implementation slice, now
[TASK-00072 — Discover and clean expired human credential work](../tasks/00072-TASK.md), carrying the entire WF-025
matrix and TICKET evidence. This is the map's full accepted TICKET/TASK decomposition; all planning phases are complete.
Consult the Board for execution selection, without reprioritizing unrelated work. The implementation shape remains one
complete, independently reviewable PR, not layer-only TASKs. Do not reuse the consumer's TASK number.

### Implementation and independent acceptance

Before implementation ask John for main-checkout versus isolated-worktree placement and reconcile unrelated dirty
work. Use a feature branch from current `develop`, the package runtime, purpose-named ignored evidence directories,
and the current work workflow. Regression-first proof, focused tests and the complete `./bin/build` gate belong to the
implementation TASK. Hand its committed tested content and receipt to an independent reviewer; behavioral QA should
exercise the nonvisual API/restart/race scenarios after technical acceptance. Publication/release are not implied.

The accepted implementation handoff must return upstream EPIC/TICKET/TASK IDs, actual implementation commit, focused
and full verification counts/coverage/warnings, public API/repository changes and a concise Agent OS composition recipe.
It must explicitly separate modeled package proof from unexecuted real PostgreSQL consumer qualification.
