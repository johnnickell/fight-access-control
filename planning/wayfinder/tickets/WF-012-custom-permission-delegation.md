# Classify custom Permissions for delegation

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** John's decision.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011

## Question

What makes a custom Permission with a null managed tier eligible for a custom Role or direct Agent assignment?

## Must decide

- Fail closed until explicit classification, or add a separate explicit custom-Permission classification and its
  owner, lifecycle, compatibility, and audit semantics. Null must not silently mean `ADMIN_SAFE`.
- Whether managed `ADMIN_SAFE` means only delegation eligibility, with actor, target, and scope separately checked.
- Whether an existing custom Permission can be reclassified and what happens to existing memberships.

## Required evidence

- Inspect Permission creation/update and all current role/Agent assignment commands, serialization, and consumer
  compatibility. Exercise null-tier and stale-definition cases.

## Resolution boundary

Set classification semantics and fail-closed defaults. Target-aware administrator authority is WF-013.

## Resolution

Open; no policy accepted.
