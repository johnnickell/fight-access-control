# Define protected Permission membership and designated Role identity

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John confirmed the membership, identity, principal-type, and classification-ownership decisions on 2026-09-25.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** —

## Question

What exactly may carry a managed `SUPER_ADMIN_ONLY` Permission, how is the designated managed Role recognized, and
who owns classification of the consumer's security-administration Permission names?

## Decision points

- Enforce the protected-membership rule even on an idempotent command encountering invalid existing authority;
  managed Permission reclassification is specified in WF-015.
- Let the consuming project's version-controlled managed definitions classify Permission names. The package has no
  built-in definitions for names such as `MANAGE_ROLE_PERMISSIONS` or `ASSIGN_SUPER_ADMIN` and does not hard-code a
  protected-name list. The package must enforce the tier declared for each managed Permission.

## Accepted constraint (2026-09-25)

John confirmed that `SUPER_ADMIN_ONLY` Permissions belong **only** to managed `ROLE_SUPER_ADMIN` and a human User,
always. A human User may obtain them only through assignment of that Role. They may never be granted directly to an
Agent, by complete-set Agent replacement, to a custom Role, to another managed Role, or directly to a User if such a
path is added later. Acting as a Super Admin does not create an exception. The current package has no direct User
Permission path. This is a package-wide invariant for every entry point, not a configurable adapter preference.

John chose recognition by the exact `ROLE_SUPER_ADMIN` name rather than a special Role ID. The Role must also be
managed and authoritative: a custom Role renamed to that name, a duplicate name, or a stale/mismatched managed
definition cannot qualify. Ordinary Role IDs remain necessary for persisted associations, but do not confer Super
Admin status by themselves. For this policy, "human" means the package `User` principal type; an `Agent` principal
never qualifies. No separate human-marker field is requested.

John confirmed that the consuming project owns managed Permission names and their `ADMIN_SAFE` or
`SUPER_ADMIN_ONLY` classification. Tiers classify Permissions, not Roles; managed Roles carry Permission IDs. A
semantic security-administration Permission incorrectly declared `ADMIN_SAFE` is a consumer-policy defect that the
package cannot infer from its name. Consumer policy review and tests must guard that classification, while the
package enforces declared `SUPER_ADMIN_ONLY` membership without exceptions.

## Required evidence

- Inspect managed-policy construction/reconciliation, Role reconstruction, custom-role grant, direct Agent grant and
  replacement, and effective-principal resolution. Test name spoofing, managed-status spoofing, and unknown or
  inconsistent policy.

## Resolution boundary

Set the protected membership invariant and policy identity contract. Custom Permission delegation, administrator
scope, elevation/removal authority, and managed Permission reclassification remain in their downstream tickets.

## Resolution

Closed. The accepted constraints above govern the v0.4.0 handoff. Managed Permission reclassification belongs to
[WF-015](WF-015-forbidden-authority-remediation.md); package transaction and unit-test proof belong to
[WF-016](WF-016-atomic-enforcement-and-proof.md). This decision authorizes no implementation.
