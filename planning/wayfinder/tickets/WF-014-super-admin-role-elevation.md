# Set Super Admin assignment and removal guarantees

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John confirmed the package/consumer boundary and User-state behavior on 2026-09-26.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011

## Question

Which authority, confirmation, audit, and last-admin rules govern assigning and removing the designated managed
Super Admin Role?

## Accepted decision (2026-09-26)

The exact name `ROLE_SUPER_ADMIN` is reserved for the designated managed Role. AccessControl must reject custom
Role creation or rename to that name and reject inconsistent stored Role data that would make a custom Role appear
under it during assignment or principal reconstruction. A supplied Role ID or adapter cannot turn a custom Role into
the managed Super Admin Role. The managed-Role and protected-Permission membership rules remain [WF-011](WF-011-protected-permission-membership.md);
managed Permission reclassification is [WF-015](WF-015-forbidden-authority-remediation.md).

Apart from that package-owned Role identity invariant, `AssignRoleToUser` and `RemoveRoleFromUser` treat the managed
`ROLE_SUPER_ADMIN` like other Roles. AccessControl does not authenticate the command caller, require
`ASSIGN_SUPER_ADMIN` or `MANAGE_USER_ROLES`, demand a confirmation token, decide who may remove the Role, or impose a
last-active-Super-Admin guard in those handlers. Per [WF-013](WF-013-target-aware-role-administration.md), the
current actor-only User Role-assignment authorization port is removed in v0.4.0. The application builder protects
each API, CLI, worker, and direct command-bus entry point. A restricted bootstrap CLI may use different caller
controls from an HTTP endpoint.

The application builder owns the elevation and removal policy: caller authority, confirmation, durable audit,
self-removal, last-active-Super-Admin protection, lifecycle transitions, recovery, and concurrent administration.
It must decide removal authority separately; permission to assign does not automatically authorize removal. The
existing Fight Agent OS planning requires explicit assignment authority and confirmation/audit in its consumer
flows; that does not become a package-owned Permission-name or protocol requirement. The application builder also
owns an idempotent request's caller checks, even when the package makes no state change.

Role IDs may be stored on pending, active, disabled, or deleted Users. Only active Users can authenticate and obtain
an effective principal. In particular, the first bootstrap may assign the managed Role to a pending User before
activation. The application builder decides who may receive, retain, regain, or lose elevated authority as User
state changes. AccessControl retains its ordinary User Role assignment revision and repository consistency rules;
WF-016 will specify atomic proof for the new package invariants.

## Required evidence

- Inspected current assign/remove handlers, User assignment revisions, repository reference fences, Role creation
  and rename, RoleName, and principal resolution. The handlers resolve Role IDs and currently call an actor-only
  authorization port; they have no special Super Admin, User-state, or last-admin check. Principal resolution
  authenticates active Users only, while the principal Role snapshot exposes the Role name. The current custom
  Role commands allow the reserved name when it is unused, so the package needs an explicit invariant.
- Inspected Fight Agent OS planning for assignment authority, confirmation, audit, and bootstrap. It is the first
  implementing consumer and has no stored Permissions. Its eventual removal and last-admin recovery policies
  remain consumer work; this ticket does not invent them.

## Resolution boundary

Set the package Role identity and User-state rules and the consumer ownership of elevation and de-elevation policy.
Managed Permission reclassification and atomic proof remain downstream.

## Resolution

Closed. John confirmed this boundary after separately settling the reserved name, last-admin ownership, and User
state. No implementation or consumer planning change is authorized by this Wayfinder decision.
