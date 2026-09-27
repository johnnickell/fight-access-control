# ADR 0010: Permission Eligibility and Caller Authorization

- Status: accepted; implemented for the unreleased v0.4.0 package
- Date: 2026-09-26

## Decision

Fight AccessControl enforces which Permissions may be attached to custom Roles and Agents. It rejects protected-tier
grants inside its commands, including Agent complete-set replacement, regardless of who dispatched them. The
application builder protects every command entry point, using controls appropriate to HTTP, CLI, workers, or other
adapters. Role administration, Agent Permission, and User Role-assignment handlers no longer call the retired
actor-only authorization ports. No new target-aware authorization port is required for those commands.
The accepted target-specific authorization checks for cross-user sessions, email-change administration, and
pending-invitation correction remain. This supersedes the affected actor-authorization requirement in completed
[TICKET-00004](../tickets/00004-TICKET.md); that ticket remains a historical record of the earlier contract.
The retained checks concern ownership of a particular User's resource. The removed generic checks are consumer
administration policy, including any relevant Permission the consumer declares `SUPER_ADMIN_ONLY`.

This boundary keeps consumer-specific administrative Permission names, target ownership, and future scope rules out
of the package. It also means the consumer must guard every command-bus entry point it exposes: the package tier
check prevents protected membership but does not reject an unauthorized `ADMIN_SAFE` change. [WF-013](../wayfinder/tickets/WF-013-target-aware-role-administration.md)
owns the detailed decision; WF-014 separately decides Super Admin Role assignment and removal.

[TASK-00042](../tasks/00042-TASK.md), [TASK-00043](../tasks/00043-TASK.md), and
[TASK-00044](../tasks/00044-TASK.md) implemented and verified this boundary for Role lifecycle, User Role assignment,
custom-Role grants, and direct Agent assignments. Consumer entry-point protection and composition changes are
documented in the [v0.4.0 migration guide](../../docs/permission-tier-v0.4-migration.md); adoption remains separate.
