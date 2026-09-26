---
id: TICKET-00009
epic: EPIC-00008
title: Delegate Eligible Permissions to Custom Roles and Agents
status: ready-for-agent
---

# Delegate Eligible Permissions to Custom Roles and Agents

## Problem and Outcome

Custom Permissions currently have a null tier, while custom-Role and direct Agent grant commands accept an existing
Permission without checking its tier. Make every Permission's tier explicit and let package commands attach only
`ADMIN_SAFE` authority to custom Roles or Agents. The application builder protects each command entry point and
decides who may change a particular target; AccessControl enforces eligibility independently of caller identity.

## Use Cases

| Actor and trigger | Commands | Queries | Events | Expected side effects and outcome |
| --- | --- | --- | --- | --- |
| A consumer creates or reads a custom Permission | Existing custom-Permission definition API; no new Command | Existing Permission reads and `PermissionView` | N/A | The custom Permission is `ADMIN_SAFE` from creation; aggregate and read results expose a non-null tier. It cannot be reclassified as protected through the custom API. |
| A consumer grants or revokes a Permission on a custom Role | `GrantPermissionToCustomRole`, `RevokePermissionFromCustomRole` | N/A | Existing custom-Role membership Events on real changes | An authoritative `ADMIN_SAFE` Permission may be granted; a protected or malformed grant fails without mutation or success Event. Managed Roles remain immutable through these commands. Revocation retains ordinary desired-state behavior. |
| A consumer grants, revokes, or replaces direct Agent Permissions | `GrantPermissionToAgent`, `RevokePermissionFromAgent`, `ReplaceAgentPermissions` | Existing Agent Permission reads | Existing Agent Permission Events on real changes | Every desired Agent membership is authoritative and `ADMIN_SAFE`. A protected item rejects the entire replacement, including an otherwise unchanged set, with no partial change or success Event. Eligible changes retain assignment-revision and set-normalization behavior. |
| An application builder invokes these package commands through API, CLI, worker, or direct bus | Existing Commands | N/A | Existing provenance and failure Events | The builder performs caller, target, Permission, and any scope checks at its entry point. Package handlers no longer require actor-only Role or Agent Permission authorization ports; an actor ID in a Command remains provenance, not proof of authority. |

No new Command, Query, or Event is required. Package failure Events retain their existing safe publication and
rethrow ordering; no success Event is emitted for rejection or an idempotent no-op.

## Validation, Transactions, and Compatibility

- A missing, null-tier, or inconsistent Permission cannot be treated as `ADMIN_SAFE`. Eligibility is checked even
  when a grant would otherwise be an idempotent no-op. Agent complete-set replacement validates the entire desired
  set; one invalid member rejects the request as a whole.
- The eligibility decision and membership write use authoritative Permission state in the same Unit of Work. Role
  and Agent repository contracts preserve tier authority through the write so a concurrent protected-tier promotion
  and grant cannot both succeed. Concrete adapter locking is a consumer choice; the corresponding promotion rule
  belongs to [TICKET-00010](00010-TICKET.md).
- `Permission::getTier()` and `PermissionView` become non-null contracts. Runtime-created custom Permissions start
  `ADMIN_SAFE`; only managed policy can declare `SUPER_ADMIN_ONLY`. Invalid reconstructed data fails closed.
- Removing the actor-only `RoleAdministrationAuthorization` dependency from the custom-Role membership handlers and
  `AgentPermissionAdministrationAuthorization` from direct Agent Permission handlers is an accepted pre-1.0
  composition change. Custom-Role create/rename/remove and User Role-assignment changes are owned by
  [TICKET-00011](00011-TICKET.md). Ownership-sensitive cross-user session, email-change, and pending-invitation
  checks remain in place.
- Consumer persistence/schema changes, including Fight Agent OS's nullable custom-Permission column, are adoption
  work. Fight Agent OS has no stored Permissions to migrate. Current package fixtures and in-memory repositories
  must reflect the non-null contract.

## Permissions and Rejection Behavior

`ADMIN_SAFE` is eligibility, not authorization for any caller, target, or scope. The consumer protects all entry
points, including no-op and direct command-bus calls. AccessControl does not add a target-aware authorization port,
authenticate an actor, or classify consumer Permission names. It rejects protected custom-Role and Agent grants
even when a Super Admin initiated the command. A failed command leaves persistence consistent, emits no success
Event, and preserves the package's existing safe failure evidence.

## Acceptance Evidence

- [ ] Custom and managed Permissions expose a non-null tier in aggregate and safe read contracts; custom creation
      produces `ADMIN_SAFE`, and unexpected null or malformed definitions fail closed.
- [ ] Real package handlers accept eligible custom-Role and Agent changes and reject protected grants, protected
      Agent complete-set members, missing definitions, and managed-Role targets without partial writes or success
      Events. Idempotent and stale-state paths do not bypass eligibility.
- [ ] Direct Agent replacement keeps normalized-set and expected-revision behavior for eligible Permissions.
- [ ] The specified actor-only Role-membership and Agent-Permission handler ports are removed while consumer
      caller-authorization responsibility and remaining ownership-sensitive ports are accurately documented.
- [ ] Focused package tests prove allowed, rejected, no-op, failure-event, and transaction-conflict outcomes; the
      canonical `./bin/build` passes during implementation.

## Decision Links and Boundaries

Implements [WF-011](../wayfinder/tickets/WF-011-protected-permission-membership.md),
[WF-012](../wayfinder/tickets/WF-012-custom-permission-delegation.md),
[WF-013](../wayfinder/tickets/WF-013-target-aware-role-administration.md),
[WF-016](../wayfinder/tickets/WF-016-atomic-enforcement-and-proof.md), and
[ADR 0009](../adr/0009-non-null-custom-permission-tier.md). Managed definition validation and tier promotion
belong to [TICKET-00010](00010-TICKET.md); reserved Role identity and User Role commands belong to
[TICKET-00011](00011-TICKET.md).

No direct User Permission grant, scoped Workspace/Repository policy, consumer authorization adapter, Agent OS
change, package tag, or release is part of this Ticket.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 41 | [TASK-00041](../tasks/00041-TASK.md) | Classify every Permission with a non-null tier | done |
| 43 | [TASK-00043](../tasks/00043-TASK.md) | Enforce tier eligibility on custom Role grants | done |
| 44 | [TASK-00044](../tasks/00044-TASK.md) | Enforce tier eligibility on direct Agent assignments | ready-for-agent |
