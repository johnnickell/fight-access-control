# Authorize Permission changes by target and scope

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** John's decision; consumer scope model is an external design dependency if delegated scopes are selected.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011, WF-012

## Question

What target-aware authority must a caller prove before changing an eligible Permission on a custom Role or Agent,
and what must fail closed until scoped administration exists?

## Must decide

- Grant and revoke policy for actor, target Role, target Permission, action, and eventual scope; distinguish
  `ADMIN_SAFE` eligibility from authority to administer a specific target.
- Direct Agent grant, revoke, and complete-set replacement policy for actor, target Agent, every target Permission,
  action, and eventual scope. A replacement set must not smuggle a forbidden Permission or bypass per-target checks.
- Whether removal of an existing forbidden membership is allowed as a guarded remediation action even though adding
  it is forbidden, and how ordinary revocation avoids an actor-only universal boolean.
- How to prevent self-elevation, indirect grant of `MANAGE_ROLE_PERMISSIONS`, managed-Role mutation, and bypass by
  direct command-bus or alternate adapter use across both Role and Agent commands.
- What installation-wide behavior is permitted now and what scoped Workspace/Repository behavior waits for an
  explicit authoritative scope model.

## Required evidence

- Inspect custom-Role and direct Agent handlers, authorization ports, actor identity/source, Role/Agent/Permission
  repositories, and consumer authorization adapters. Define success, denial, no-op, missing authority, and
  invalid-scope examples for single changes and complete-set replacement.

## Resolution boundary

Set package and consumer authorization responsibilities for custom-Role and direct Agent Permission changes.
User-role elevation is WF-014; transaction mechanics and proof are WF-016.

## Resolution

Open; no policy accepted.
