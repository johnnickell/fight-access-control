---
id: TICKET-00011
epic: EPIC-00008
title: Reserve Super Admin Role Across User Role Administration
status: ready-for-agent
---

# Reserve Super Admin Role Across User Role Administration

## Problem and Outcome

The current custom-Role commands can use `ROLE_SUPER_ADMIN` when that name is available, and User Role handlers
rely on an actor-only authorization port that cannot decide target-specific elevation policy. Reserve the exact
name for the managed Role and keep ordinary package assignment/removal semantics. The application builder owns
caller authority, confirmation, audit, last-admin protection, and recovery at every entry point.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A consumer creates, renames, or removes a custom Role | `CreateCustomRole`, `RenameCustomRole`, `RemoveCustomRole` | Existing Role reads | Existing Role Events on real changes | Creation or rename to exact `ROLE_SUPER_ADMIN` fails. Other custom-Role changes retain their ordinary lifecycle; managed Roles remain immutable through runtime commands. |
| A consumer loads a Role for User assignment or principal construction | `AssignRoleToUser` where applicable | Authoritative Role and principal reads | Existing assignment Event only after a real committed change | A custom, duplicate, stale, or inconsistent stored Role cannot impersonate the authoritative managed `ROLE_SUPER_ADMIN` through its name or ID. |
| A consumer assigns or removes a Role on a User | `AssignRoleToUser`, `RemoveRoleFromUser` | Existing User Role/principal reads | `RoleAssignedToUser`, `RoleRemovedFromUser` on real changes | The managed Super Admin Role follows ordinary package association, revision, and reference-integrity rules. It may be assigned to a pending User for bootstrap; only active Users authenticate. Other User states retain existing storage behavior. |
| An application builder exposes Role management or User Role commands | Existing Commands | N/A | Existing provenance/failure Events | The builder protects API, CLI, worker, and direct command-bus entry points. Package handlers no longer require actor-only Role-administration or User Role-assignment authorization ports. |

No new Command, Query, or Event is required. The package continues to use Role IDs for persisted associations;
the ID alone cannot establish the Super Admin identity.

## Validation, Transactions, and Compatibility

- Only an authoritative managed Role whose exact name is `ROLE_SUPER_ADMIN` qualifies. Custom creation, rename,
  and reconstruction reject that reserved name; duplicate or mismatched managed identity fails closed. These
  checks apply before assignment or principal reconstruction can present a custom Role as Super Admin.
- User Role commands retain their existing atomic mutation, expected revision, repository consistency, no-op,
  safe failure Event, and post-commit success Event behavior. The package adds no special confirmation token,
  assignment Permission check, removal Permission check, or last-admin count.
- Remove `RoleAdministrationAuthorization` from custom-Role create/rename/remove handlers and
  `UserRoleAssignmentAdministrationAuthorization` from assign/remove handlers. Its removal from custom-Role
  membership handlers belongs to [TICKET-00009](00009-TICKET.md). Commands may retain actor IDs for provenance,
  but those IDs are not package authentication or authorization evidence.
- These are accepted pre-1.0 constructor/composition changes. Consumer adapters must protect every command entry
  point, including idempotent calls. Ownership-sensitive authorization for cross-user sessions, email changes, and
  pending-invitation correction stays in AccessControl.

## Permissions and Rejection Behavior

The application builder decides assignment and removal authority separately, confirmation, durable audit,
self-removal, final-active-Super-Admin protection, lifecycle handling, recovery, and any Workspace/Repository scope.
It may protect a restricted bootstrap CLI differently from HTTP. AccessControl does not prescribe
`ASSIGN_SUPER_ADMIN`, `MANAGE_USER_ROLES`, or any other consumer Permission name. Invalid Role identity fails
without mutation or success Event; ordinary eligible assignments and removals keep their existing outcomes.

## Acceptance Evidence

- [ ] Custom creation, rename, and inconsistent reconstruction cannot use the exact `ROLE_SUPER_ADMIN` name;
      neither an arbitrary Role ID nor adapter data can impersonate the managed Role in User assignment or principal
      resolution.
- [ ] Ordinary User Role assignment/removal, including pending-User bootstrap assignment, retain revisions,
      reference integrity, no-op behavior, rollback, and event ordering; active-only principal authentication
      remains unchanged.
- [ ] The specified actor-only custom-Role lifecycle and User Role-assignment ports are removed from package
      handlers. Ownership-sensitive cross-user session, email-change, and invitation checks remain.
- [ ] Focused package tests prove accepted and rejected Role identity paths and ordinary User Role behavior; the
      canonical `./bin/build` passes during implementation.

## Decision Links and Boundaries

Implements [WF-011](../wayfinder/tickets/WF-011-protected-permission-membership.md),
[WF-013](../wayfinder/tickets/WF-013-target-aware-role-administration.md), and
[WF-014](../wayfinder/tickets/WF-014-super-admin-role-elevation.md). Managed-policy protection belongs to
[TICKET-00010](00010-TICKET.md); custom-Role membership commands belong to
[TICKET-00009](00009-TICKET.md).

No consumer-specific assignment confirmation, audit protocol, last-admin algorithm, Agent OS implementation,
package tag, or release is part of this Ticket.

## Child Tasks

No Tasks recorded.
