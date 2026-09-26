# Authorize custom-role Permission changes by target and scope

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Open
**Gate:** John's decision; consumer scope model is an external design dependency if delegated scopes are selected.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011, WF-012

## Question

What target-aware authority must a caller prove before granting or revoking an eligible Permission on a custom Role,
and what must fail closed until scoped administration exists?

## Must decide

- Grant and revoke policy for actor, target Role, target Permission, action, and eventual scope; distinguish
  `ADMIN_SAFE` eligibility from authority to administer a specific target.
- Whether removal of an existing forbidden membership is allowed as a guarded remediation action even though adding
  it is forbidden, and how ordinary revocation avoids an actor-only universal boolean.
- How to prevent self-elevation, indirect grant of `MANAGE_ROLE_PERMISSIONS`, managed-Role mutation, and bypass by
  direct command-bus or alternate adapter use.
- What installation-wide behavior is permitted now and what scoped Workspace/Repository behavior waits for an
  explicit authoritative scope model.

## Required evidence

- Inspect custom-Role handlers, authorization ports, actor identity/source, role/permission repositories, and
  consumer authorization adapters. Define success, denial, no-op, missing authority, and invalid-scope examples.

## Resolution boundary

Set package and consumer authorization responsibilities for custom-Role Permission changes. User-role elevation is
WF-014; transaction mechanics and proof are WF-016.

## Resolution

Open; no policy accepted.
