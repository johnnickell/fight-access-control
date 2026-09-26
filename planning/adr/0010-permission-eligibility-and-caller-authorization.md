# ADR 0010: Permission Eligibility and Caller Authorization

- Status: accepted for v0.4.0 planning; implementation pending
- Date: 2026-09-26

## Decision

Fight AccessControl enforces which Permissions may be attached to custom Roles and Agents. It rejects protected-tier
grants inside its commands, including Agent complete-set replacement, regardless of who dispatched them. The
application builder protects every command entry point, using controls appropriate to HTTP, CLI, workers, or other
adapters. Role administration, Agent Permission, and User Role-assignment handlers will no longer call their
existing actor-only authorization ports. No new target-aware authorization port is required for those commands.
The accepted target-specific authorization checks for cross-user sessions, email-change administration, and
pending-invitation correction remain. This supersedes the affected actor-authorization requirement in completed
[TICKET-00004](../tickets/00004-TICKET.md); that ticket remains a historical record of the earlier contract.
The retained checks concern ownership of a particular User's resource. The removed generic checks are consumer
administration policy, including any relevant Permission the consumer declares `SUPER_ADMIN_ONLY`.

This boundary keeps consumer-specific administrative Permission names, target ownership, and future scope rules out
of the package. It also means the consumer must guard every command-bus entry point it exposes: the package tier
check prevents protected membership but does not reject an unauthorized `ADMIN_SAFE` change. [WF-013](../wayfinder/tickets/WF-013-target-aware-role-administration.md)
owns the detailed decision; WF-014 separately decides Super Admin Role assignment and removal.
