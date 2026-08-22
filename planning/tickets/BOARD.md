# Ticket Board

Operational execution view for Fight AccessControl. Ticket files are canonical for scope, status, and
`blocked_by` edges; this board is canonical for recommended order. Update this file whenever ticket status,
dependencies, or roadmap priority changes.

Last updated: 2026-08-22

## Ready Frontier

These tickets have no unfinished blockers. Work top to bottom unless current context makes another ready ticket
materially cheaper.

| Rank | Ticket | Parent PRD | Why next |
| --- | --- | --- | --- |
| 1 | [T-00010 — Change and correct identity journeys](T-00010-change-correct-identity-journeys.md) | [PRD-00001](../specs/00001-PRD.md) | Builds the next identity lifecycle journeys on completed principal authority. |
| 2 | [T-00011 — Administer account lifecycle](T-00011-administer-account-lifecycle.md) | [PRD-00001](../specs/00001-PRD.md) | Adds administrative lifecycle transitions on completed session and principal foundations. |
| 3 | [T-00012 — Reconcile managed policy and custom roles](T-00012-reconcile-managed-policy-custom-roles.md) | [PRD-00001](../specs/00001-PRD.md) | Owns managed policy, custom-role management, and authorized element-level User assignment commands. |

## Waiting

Waiting tickets retain `ready-for-agent`; their position here is derived from unfinished `blocked_by` edges.

| Suggested order | Ticket | Parent PRD | Waiting on |
| --- | --- | --- | --- |
| 4 | [T-00013 — Publish versioned public contracts](T-00013-publish-versioned-public-contracts.md) | [PRD-00001](../specs/00001-PRD.md) | T-00012 |

## In Progress

| Ticket | Parent PRD | Remaining gap |
| --- | --- | --- |
| [T-00014 — Unify password-reset grant persistence](T-00014-unify-password-reset-grant-persistence.md) | [PRD-00001](../specs/00001-PRD.md) | Enforce valid initial issuance and preserve extensible aggregate subtypes across transitions. |
| [T-00015 — Unify activation-grant persistence](T-00015-unify-activation-grant-persistence.md) | [PRD-00001](../specs/00001-PRD.md) | Enforce claim-before-outcome transitions, valid initial issuance, and subtype-preserving transitions. |

## Needs Info

No tickets currently require a decision authority.

## Completed

| Ticket | Parent PRD | Outcome |
| --- | --- | --- |
| [T-00001 — Invite a pending user](T-00001-invite-pending-user.md) | [PRD-00001](../specs/00001-PRD.md) | Canonical pending-user invitation Command, Event, handler, and Domain repositories with durable activation work, audit evidence, and exact statement coverage. |
| [T-00002 — Recover and resend activation delivery](T-00002-recover-resend-activation-delivery.md) | [PRD-00001](../specs/00001-PRD.md) | Safe delivery-status query, retryable delivery work, atomic predecessor-grant revocation and replacement, and exact statement coverage. |
| [T-00003 — Activate an invited account](T-00003-activate-invited-account.md) | [PRD-00001](../specs/00001-PRD.md) | Atomic activation grant redemption, initial credential, active identity transition, and first server-side session with exact coverage. |
| [T-00004 — Login, cold restore, and current-session logout](T-00004-login-restore-logout.md) | [PRD-00001](../specs/00001-PRD.md) | Synchronous secret-safe authentication service issuing access JWTs and opaque refresh credentials with authoritative cold refresh and current-only logout. |
| [T-00005 — Secure refresh-session rotation](T-00005-secure-refresh-session-rotation.md) | [PRD-00001](../specs/00001-PRD.md) | One-winner refresh rotation, secret-free bounded conflicts, and fail-closed used-credential family revocation with exact coverage. |
| [T-00006 — Manage active sessions](T-00006-manage-active-sessions.md) | [PRD-00001](../specs/00001-PRD.md) | Safe active-session views, owned-session revocation, and authorized reasoned administrative intervention with atomic audit evidence. |
| [T-00007 — Recover a forgotten password](T-00007-recover-forgotten-password.md) | [PRD-00001](../specs/00001-PRD.md) | Generic recovery requests, historically unique one-time reset authority, generation-bound delivery lifecycle, atomic credential replacement and complete session revocation. |
| [T-00008 — Change an authenticated password](T-00008-change-authenticated-password.md) | [PRD-00001](../specs/00001-PRD.md) | Current-password proof, atomic credential and authentication-authority replacement, complete session revocation, and durable audit evidence. |
| [T-00009 — Establish principals and authorization primitives](T-00009-principals-authorization-primitives.md) | [PRD-00001](../specs/00001-PRD.md) | Context-only consumer composition, request-scoped authoritative principal resolution, and foundational Role, Permission, and User assignment state. |
