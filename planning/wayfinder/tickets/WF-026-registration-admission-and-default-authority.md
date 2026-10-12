# Define registration admission and default authority

**Labels:** `wayfinder:grilling`
**Mode:** HITL
**Status:** Closed
**Gate:** —
**Map:** [Optional user self-registration for v1.0.0](../self-registration-v1-map.md)
**Depends on:** —

## Question

How is a consumer-selected default Role set safely determined and attached to the new pending User, given that consumers own whether registration is exposed?

## Must decide

- Consumer-owned registration exposure and constructor-supplied Role names, not untrusted request-selected Roles.
- Nonempty defaults, missing/forbidden Role rejection, and one atomic pending-identity/Role/grant/delivery/audit outcome with authoritative Role references and canonical-email uniqueness.

## Required evidence

- John confirmed the consumer owns registration exposure/enablement; AccessControl provides the registration use-case commands, queries and handlers, without its own installation-enable guard. John chose consumer-configured RoleName strings at handler construction, resolved from the Role repository for automatic assignment. John subsequently approved a nonempty Role list, whole-registration rejection on any missing name and rejection of `ROLE_SUPER_ADMIN` as a default.
- `RoleName::fromString()` validates canonical `ROLE_` names; `RoleRepository::getByName()` returns one authoritative Role or null and rejects conflicting stored identities. `User::assignRole()` accepts Role IDs on pending Users; the existing invitation creates no Roles, and a separate `AssignRoleToUserHandler` uses a transaction-fenced assignment operation. No current invitation path provides atomic default Role bootstrap.
- `InvitePendingUserHandler` already commits pending User, grant, delivery and audit in one `TransactionalUnitOfWork`, with `UserInvited` after commit. `UserRepository::add()` requires atomic canonical-email/reservation uniqueness; separate `AssignRoleToUserHandler` validates current Role and uses a fenced role-assignment write. Registration must extend the atomic creation boundary to default Roles; those current paths alone do not qualify creation-time Role reference safety. The project profile assigns production persistence and transaction adapters to consumers, not the package.
- John explicitly agreed to the recommended atomic outcome: any failure (including duplicate email or concurrent Role change) leaves none of the registration effects; provider email sending remains after commit in the recoverable delivery flow.

## Resolution boundary

This ticket settles the admission/Role bootstrap contract, not HTTP routing, database schema or the mail-context mechanism. Do not assume anonymous requests can use `InvitePendingUser` or silently make `ROLE_USER` globally required. Consumers protect every exposed entry point, including non-HTTP callers they enable. Carry public-request abuse/non-enumeration questions into the map; [WF-027 — Distinguish registration mail from private invitations](WF-027-registration-delivery-origin.md) handles durable registration mail context.

## Resolution

**Settled by John:** AccessControl supplies the registration use-case commands, queries and matching handlers. Whether a Register User page is present or a registration operation is used is a consumer-project concern, including installation-specific database configuration; the package does **not** enforce an enabled/disabled installation setting. Do not repurpose the private invitation as an anonymous caller entry point. The package must make private invitations distinguishable from public registrations at the consumer-owned provider/template boundary; WF-027 will settle how that information persists through delivery and recovery. John then chose a consumer-supplied list of `RoleName` strings at registration command-handler construction. The handler resolves the current Role(s) by name from the repository and assigns them automatically; the public registration command does not carry caller-selected Role names/IDs. This does not prescribe `ROLE_USER` globally or add a new Role storage mechanism. John approved requiring a nonempty configured list, rejecting the whole registration when any configured name does not resolve, and disallowing `ROLE_SUPER_ADMIN` as a self-registration default. On those rejections no pending User or delivery may remain. This does not impose a blanket classification on other consumer Roles. Registration must commit canonical-email-unique pending User, all resolved default-Role assignments, activation grant, encrypted recoverable delivery and audit as one atomic operation. Role resolution/assignment must remain authoritative through commit: a missing, removed or concurrently changed Role, a duplicate canonical email, or any other pre-commit failure rolls back every registration effect. No orphan pending User, partial Role set, grant, delivery or audit may survive a failed commit; post-commit notification/provider faults do not undo committed state. Email invocation remains post-commit through package delivery. Exact repository fencing/API and error response shape belong to subsequent design. WF-026 is Closed; WF-027 is the next Wayfinder decision.
