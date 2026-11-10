# ADR 0010: Permission Eligibility and Caller Authorization

- Status: accepted; original decision implemented for v0.4.0; request-time authorization amendment approved, not implemented
- Date: 2026-09-26

## Approved amendment: request-time authorization

John approved the following policy and the invitation/registration-recovery cutover after inspecting the
current package at `0878fc2`. This is an approved target for the next release, not implementation or release
acceptance. The original decision and completed TASK evidence below remain historical wherever superseded.

Every exposed HTTP, CLI, MCP, queue-worker or other entry authenticates its actual caller and checks current
operation permission and exact target/scope before dispatch. Supplied actor IDs, saved UI decisions and earlier
requests grant no authority. An asynchronous worker checks authority when it starts its protected operation;
permission to enqueue is not permission for every later worker operation. The configured in-process bus is trusted
infrastructure, not another blanket authentication/permission boundary.

An ordinary admitted request may finish after concurrent permission revocation. The next request checks current
authority again. Do not lock permission through transaction completion, or repeat a completed permission check,
solely to catch revocation during the same ordinary request. This applies to ordinary User administration, Agent
issuance/rotation and outcome reads as well as invitation correction and registration recovery.

For `CorrectPendingInvitation` and `RecoverPendingRegistration`, remove the redundant
`InvitationAdministrationAuthorization` and `RegistrationRecoveryAuthorization` ports, their handler dependencies
and calls, and their now-unused denial exceptions/test doubles. Update callers directly: entries own actual caller,
operation and exact-target admission before dispatch. Do not replace the removed ports with permissive callbacks,
ambient trust flags, compatibility modes, forwarding wrappers or another authorization layer. This supersedes the
original retained invitation-port requirement below and TICKET-00023/TASK-00075's additional internal admission
requirement; it does not make recovery public or anonymous. Session/email-change ports have no explicit
through-commit permission promise; this amendment does not require their unrelated API removal.

Preserve business safeguards independently of caller permission lifetime:

- Invitation/registration origin and eligibility, expiry, canonical-email uniqueness and reservations, expected
  generations, complete-state comparisons, predecessor invalidation and exact User/Role preservation.
- Credential/session identity and state consistency, competing rotation/revocation protection, required atomic
  mutation/audit, post-commit facts and truthful uncertain outcomes.
- Operation-result identity, ownership and target scope before disclosure. A lookup needed to discover the target
  may precede its target-specific check; it does not justify repeating the same permission check after the read.
- Delivery attempt/credential/destination/generation and receipt binding. Late delivery must not revive obsolete
  credentials, acknowledge a replacement or make stale bytes usable. Each separate worker stage has fresh admission.
- Key retirement's exclusion of new key users and authoritative reference checks, plus cleanup history preventing
  stale replay. These protect stored secrets and recoverable effects, not the maintainer's permission lifetime.
- Continuation's trusted provenance, credential/handoff validity and fresh authority for each new protected action.
  Repeated work cannot reuse the first action's permission indefinitely. Separate business-state fences from
  permission-only writer exclusion; fresh per-action admission is not itself a reason to lock permission until
  action completion.

EPIC-00009 D2's protected-sink, claim/admission/outcome, fixed destination, ordering, expiry, receipt, activation/use
and recovery guarantees remain. Its permission-through-ordinary-commit requirement is superseded only where it
serves solely to serialize caller permission revocation. Preserve destination, delegation/handoff binding and
credential consistency controls needed by those distinct operations; do not remove a combined fence without
separating its purposes. Any stronger permission-lifetime exception requires an explicit concrete harm and separate
maintainer approval; existing code/contracts alone are not justification.

Reuse TASK-00137 for invitation/recovery ownership and TASK-00143/00144/00145 for issuance, worker and continuation
ownership when preparing the bounded implementation amendments. TASK-00010, TASK-00075, TICKET-00023 and
EPIC-00009 retain their original decisions/evidence with this visible supersession. No existing completed TASK or
independent report establishes implementation acceptance of this amendment. Selecting/invoking implementation
remains separate; no source work, publication, merge or release is authorized by this planning acceptance.

Implementation evidence must show denial before protected work, an admitted ordinary operation finishing despite
concurrent permission removal, and a later request refusing. Preserve adversarial business-race, rollback, exact
scope/disclosure, audit, delivery and key-retirement tests. Remove tests requiring permission writers to wait solely
for ordinary commit; do not relabel business-state race tests as obsolete permission tests. Qualify actual consumer
entry admission and persistence separately from package models.

## Original decision

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
