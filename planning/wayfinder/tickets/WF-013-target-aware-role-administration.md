# Separate Permission eligibility from caller authorization

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** John clarified on 2026-09-26 that the application builder protects all command entry points.
**Map:** [Enforce permission grant tiers for v0.4.0](../permission-grant-tiers-v0-4-0-map.md)
**Depends on:** WF-011, WF-012

## Question

Which rules belong inside AccessControl when a consumer changes a custom Role or Agent Permission assignment, and
which authorization decisions belong to the consumer?

## Accepted decision (2026-09-26)

AccessControl owns Permission eligibility, not the caller's authority. It rejects a `SUPER_ADMIN_ONLY` Permission
added to a custom Role or directly to an Agent, including through complete-set Agent replacement, even when the
caller is a Super Admin. It continues to reject runtime mutation of managed Roles. A replacement request is checked
as one complete desired set; one forbidden Permission rejects the entire request. Missing, malformed, or inconsistent
Permission definitions fail rather than becoming implicitly `ADMIN_SAFE`.

The application builder protects every way to invoke a command. An HTTP route may authenticate a User and check
their permissions; a restricted CLI may use its own operator access controls. The consumer chooses appropriate
target, Permission, Workspace/Repository scope, and self-elevation rules for each entry point. AccessControl does
not require a consumer-supplied target-aware authorization port or prescribe permission names such as
`MANAGE_ROLE_PERMISSIONS`. The existing actor-only authorization calls in the affected Role and Agent Permission
handlers are to be removed as part of v0.4.0 implementation. John further clarified that the same applies to
actor-only checks in custom Role creation/rename/removal and User Role assignment/removal. This supersedes the
actor-authorization requirement for those commands in completed [TICKET-00004](../../tickets/00004-TICKET.md)
without rewriting that historical record. The package retains the accepted target-specific authorization checks
for cross-user sessions, email-change administration, and pending-invitation correction. Commands may retain actor
IDs for provenance, but AccessControl does not authenticate or trust those IDs as proof of authority. Direct
command-bus use is an entry point the application builder must protect.

John distinguished the retained checks as ownership-sensitive flows. The removed checks ask only whether an actor
may perform a broad administrative category; the application builder can enforce its own managed Permission
classification, including `SUPER_ADMIN_ONLY` where it declares that tier. This decision does not classify any
consumer Permission name on the package's behalf.

`ADMIN_SAFE` means eligible to attach, not proof that the caller may do so. The package cannot detect a consumer
misclassifying a security-administration Permission as `ADMIN_SAFE`; that is a consumer policy and test obligation.
The package also cannot prevent an unauthorized caller from using a command-bus entry point if the consumer
exposes it without appropriate protection. Historical forbidden membership cleanup remains WF-015, and transaction
and event proof remain WF-016. No current Fight Agent OS Permission data is assumed.

## Required evidence

- Inspected custom-Role, User Role-assignment, and direct Agent handlers; all six current authorization ports;
  Permission/Role/Agent repositories; and the first consumer's planning and source. Fight Agent OS has no implemented
  administration authorization adapter or stored Permissions. The current package handlers invoke actor-only ports
  but do not inspect Permission tiers.

## Resolution boundary

Set package eligibility and consumer caller-authorization responsibilities for custom-Role and direct Agent
Permission changes, and identify the other actor-only checks that must be removed. User-role elevation invariants
are WF-014; historical remediation is WF-015; transaction mechanics and proof are WF-016.

## Resolution

Closed. This decision supersedes the package-invoked target-aware authorization sentence in the original WF-012
resolution and ADR 0009; those records are corrected in this session. No implementation is authorized.
