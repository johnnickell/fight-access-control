# Detect and remedy historical forbidden authority

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** John's migration/remediation decision after evidence from persisted and in-memory authorities.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011

## Question

How should managed reconciliation and a guarded migration detect, prevent, and remedy protected Permission
memberships already stored outside the designated Role?

Fight Agent OS is the first implementing consumer and, per John on 2026-09-25, has no stored Permissions yet. This
ticket still decides how the package detects and handles forbidden or malformed authority in future consumers,
reclassification, and dirty fixtures; it must not assume a live Agent OS grant inventory.

## Must decide

- Reject malformed managed definitions before any authority changes, including protected membership in an ordinary
  managed Role and designation/name/ID mismatch.
- Inventory custom Roles, managed Roles, direct Agents, and derived/in-memory projections; distinguish source of
  truth from cached effective authority and report counts without leaking sensitive state.
- Whether to halt reconciliation or authorization when forbidden historical state is found, and who authorizes
  quarantine/removal, rollback, rescan, and audit. Avoid silently deleting legitimate unrelated authority.
- How reclassification of an existing managed Permission to `SUPER_ADMIN_ONLY` interacts with current memberships.

## Required evidence

- Inspect planner, reconciliation transaction, repositories, effective-principal snapshots, and consumer persistence
  schema/projections. Prove partial failure and repeat-run behavior with dirty fixtures.

## Resolution boundary

Set data-safety and recovery policy, not its final migration implementation. Transaction design is WF-016.

## Resolution

Open; no policy accepted.
