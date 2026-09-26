# Classify custom Permissions for delegation

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John confirmed the tier, delegation, Agent, and no-existing-data decisions on 2026-09-25.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011

## Question

How should every Permission receive a non-null tier, and when may an `ADMIN_SAFE` Permission be assigned to a custom
Role or Agent?

## Must decide

- Give every Permission a non-null tier, including runtime-created custom Permissions.
- Define `ADMIN_SAFE` as delegation eligibility, subject to actor and target authorization.
- Include or exclude direct Agent grants and complete-set replacement.
- Address the first consumer's current nullable schema and any stored custom Permission data.

## Required evidence

- Inspect Permission creation/update and all current role/Agent assignment commands, serialization, and consumer
  compatibility. Exercise null-tier and stale-definition cases.

## Accepted decision (2026-09-25)

Every Permission has a non-null `PermissionTier`. A runtime-created custom Permission is `ADMIN_SAFE` at construction;
it cannot become `SUPER_ADMIN_ONLY` or be reclassified through a custom Permission command. That protected tier is
reserved for managed Permissions under [WF-011](WF-011-protected-permission-membership.md). There is no separate
custom-Permission tier system and no null-as-`ADMIN_SAFE` fallback for malformed or stale authority.

`ADMIN_SAFE` means eligible for controlled delegation. It does not itself authorize an actor or a target. The
consuming application decides actor, target, and eventual scope authority, and the package-owned command boundary
must invoke that decision for every supported entry point. The exact target-aware contract is WF-013. An authorized
`ADMIN_SAFE` Permission may be assigned to a custom Role or directly to an Agent, including through Agent
complete-set replacement. `SUPER_ADMIN_ONLY` is never available to an Agent.

John confirmed that Fight Agent OS is the **first implementing consumer and has no stored Permissions**. Its current
nullable custom-Permission database constraint, repository reconstruction, and package fixtures show supported
behavior, not an existing authority inventory. The v0.4.0 handoff must update those contracts for non-null tiers;
there is no actual Agent OS Permission data to migrate. Any unexpected null-tier definition or membership in another
consumer or corrupt fixture fails closed pending a guarded data decision in WF-015.

## Resolution boundary

Set classification semantics and fail-closed defaults. Target-aware administrator authority is WF-013.

## Resolution

Closed. [ADR 0009](../../adr/0009-non-null-custom-permission-tier.md) records the modeling rationale. The accepted
decision above governs v0.4.0 planning. Package/domain API, query-view serialization,
consumer schema/adapter, and behavior-test changes are implementation handoff requirements, not changes made in this
Wayfinder session.
