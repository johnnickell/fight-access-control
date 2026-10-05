# Wayfinder Map: Human credential-delivery expiry cleanup

**Label:** `wayfinder:map`
**Status:** Active

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

No Wayfinder decision remains. The next planning operation is the single [approved EPIC handoff](#approved-epic-handoff).
The map remains Active only until that approved destination is written and linked; this is not an implementation gate.

## Not yet specified (fog)

No additional product-policy uncertainty is currently known. Exact PHP names, serialization and schema shapes are
proposals constrained by WF-025; freeze them in approved requirement/TASK records. New ambiguity found during design
must return for a decision rather than silently broaden this scope.

## Out of scope

- Production persistence adapters, SQL/schema implementation, another queue, consumer retry/terminalization policy,
  cryptographic key operations, provider integrations and consumer product/email-change wiring.
- Agent OS changes, Composer-lock updates, dependency reordering or claims that its TASK/adoption is complete.
- Legacy bridges, old-release backports, historical readers/migrations/backfills, destructive resets or release tooling.
- Tagging, signing, pushing, PR publication, merging, package publication and deployment without separate authority.

## Planning and delivery handoff

### Approved EPIC handoff

**Destination title:** Package-Owned Human Credential Expiry Cleanup for v0.5.0

**Status:** Approved destination; EPIC record unwritten. No other EPIC destination belongs to this map.

Write this bounded EPIC through the normal grill handoff, reusing settled WF-025 behavior without asking John to
reapprove family policy. Seek acceptance of its TICKET requirements and then TASK decomposition; the expected
implementation shape is one complete, independently reviewable PR, not layer-only TASKs. Do not assign speculative
upstream IDs or reuse the consumer's TASK number. Close the map when the resulting EPIC record is linked.

### Implementation and independent acceptance

Before implementation ask John for main-checkout versus isolated-worktree placement and reconcile unrelated dirty
work. Use a feature branch from current `develop`, the package runtime, purpose-named ignored evidence directories,
and the current work workflow. Regression-first proof, focused tests and the complete `./bin/build` gate belong to the
implementation TASK. Hand its committed tested content and receipt to an independent reviewer; behavioral QA should
exercise the nonvisual API/restart/race scenarios after technical acceptance. Publication/release are not implied.

The accepted implementation handoff must return upstream EPIC/TICKET/TASK IDs, actual implementation commit, focused
and full verification counts/coverage/warnings, public API/repository changes and a concise Agent OS composition recipe.
It must explicitly separate modeled package proof from unexecuted real PostgreSQL consumer qualification.
