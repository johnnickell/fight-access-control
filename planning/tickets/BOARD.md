# Ticket Board

Operational execution view for Fight AccessControl. Ticket files are canonical for scope, status, and
`blocked_by` edges; this board is canonical for recommended order. Update this file whenever ticket status,
dependencies, or roadmap priority changes.

Last updated: 2026-08-19

## Ready Frontier

These tickets have no unfinished blockers. Work top to bottom unless current context makes another ready ticket
materially cheaper.

| Rank | Ticket | Parent PRD | Why next |
| --- | --- | --- | --- |
| 1 | [T-00005 — Secure refresh-session rotation](T-00005-secure-refresh-session-rotation.md) | [PRD-00001](../specs/00001-PRD.md) | Builds rotating refresh continuity and compromise handling on the completed login/session foundation. |
| 2 | [T-00006 — Manage active sessions](T-00006-manage-active-sessions.md) | [PRD-00001](../specs/00001-PRD.md) | Extends current-session authority into user and administrator session management. |
| 3 | [T-00007 — Recover a forgotten password](T-00007-recover-forgotten-password.md) | [PRD-00001](../specs/00001-PRD.md) | Adds the next credential-recovery journey now that authenticated-session invalidation is established. |
| 4 | [T-00008 — Change an authenticated password](T-00008-change-authenticated-password.md) | [PRD-00001](../specs/00001-PRD.md) | Builds authenticated credential change on the completed login and restoration seams. |
| 5 | [T-00009 — Establish principals and authorization primitives](T-00009-principals-authorization-primitives.md) | [PRD-00001](../specs/00001-PRD.md) | Generalizes the current authenticated-session authority into reusable principal and authorization contracts. |

## Completed

| Ticket | Parent PRD | Outcome |
| --- | --- | --- |
| [T-00001 — Invite a pending user](T-00001-invite-pending-user.md) | [PRD-00001](../specs/00001-PRD.md) | Canonical pending-user invitation Command, Event, handler, and Domain repositories with durable activation work, audit evidence, and exact statement coverage. |
| [T-00002 — Recover and resend activation delivery](T-00002-recover-resend-activation-delivery.md) | [PRD-00001](../specs/00001-PRD.md) | Safe delivery-status query, retryable delivery work, atomic predecessor-grant revocation and replacement, and exact statement coverage. |
| [T-00003 — Activate an invited account](T-00003-activate-invited-account.md) | [PRD-00001](../specs/00001-PRD.md) | Atomic activation grant redemption, initial credential, active identity transition, and first server-side session with exact coverage. |
| [T-00004 — Login, cold restore, and current-session logout](T-00004-login-restore-logout.md) | [PRD-00001](../specs/00001-PRD.md) | Canonical login with timing-uniform failure, authoritative cold restoration, current-only logout, and exact statement coverage. |

## Waiting

Waiting tickets retain `ready-for-agent`; their position here is derived from unfinished `blocked_by` edges.

| Suggested order | Ticket | Parent PRD | Waiting on |
| --- | --- | --- | --- |
| 9 | [T-00010 — Change and correct identity journeys](T-00010-change-correct-identity-journeys.md) | [PRD-00001](../specs/00001-PRD.md) | T-00002, T-00007, T-00009 |
| 10 | [T-00011 — Administer account lifecycle](T-00011-administer-account-lifecycle.md) | [PRD-00001](../specs/00001-PRD.md) | T-00006, T-00009 |
| 11 | [T-00012 — Reconcile managed policy and custom roles](T-00012-reconcile-managed-policy-custom-roles.md) | [PRD-00001](../specs/00001-PRD.md) | T-00009 |
| 12 | [T-00013 — Publish versioned public contracts](T-00013-publish-versioned-public-contracts.md) | [PRD-00001](../specs/00001-PRD.md) | T-00005, T-00009, T-00012 |

## Needs Info

No tickets currently require a decision authority.
