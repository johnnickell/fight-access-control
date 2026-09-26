# Guard managed Permission reclassification

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John confirmed the narrowed reclassification rule and scope on 2026-09-26.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011

## Question

What must AccessControl do when a consumer changes a managed Permission from `ADMIN_SAFE` to `SUPER_ADMIN_ONLY`?

Fight Agent OS is the first implementing consumer and has no stored Permissions. This ticket does not posit an Agent
OS cleanup migration.

## Accepted decision (2026-09-26)

- John chose rejection, with no partial policy change or automatic removal, if a Permission being reclassified to
  `SUPER_ADMIN_ONLY` is still assigned to a custom Role, an ordinary managed Role, or an Agent. Those memberships
  must be removed before retrying the policy change.
- The accepted [WF-011](WF-011-protected-permission-membership.md) membership rule already requires malformed
  managed definitions to fail before reconciliation changes authority. Only the managed `ROLE_SUPER_ADMIN` may
  include a protected Permission.
- Fight Agent OS has no stored Permissions. This decision does not call for a historical inventory, quarantine,
  automatic cleanup command, or consumer migration. It adds no principal-resolution rule beyond the settled
  package invariants in WF-011 and WF-014.

## Required evidence

- Inspected managed-policy construction and planner, reconciliation transaction, Role/Agent/Permission repository
  contracts, in-memory repositories, and the first consumer's Permission persistence schema. Current reconciliation
  changes tier without checking Role or Agent memberships. This is a source observation, not a claim that Fight
  Agent OS has invalid stored data.
- Implementation evidence should prove pre-change rejection of an ineligible reclassification and no partial
  reconciliation write or success event. Atomic proof remains WF-016.

## Resolution boundary

Set only the package behavior for a managed Permission reclassification. Transaction design is WF-016. No generic
data migration is planned here.

## Resolution

Closed. John confirmed the narrower package rule. No implementation is authorized.
