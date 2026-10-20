---
id: TICKET-00023
epic: EPIC-00013
title: Recover a pending registration administratively
status: in-progress
---

# Recover a pending registration administratively

## Problem and Outcome

An unactivated registration can have an incorrect email or an expired activation at the same email. Neither
anonymous re-registration nor the private-invitation correction operation may take over that pending identity.
Supply a distinct, authorized administrator registration-recovery use case that retains the pending User and
its assigned Roles while issuing new registration-origin activation authority. This follows
[WF-027](../wayfinder/tickets/WF-027-registration-delivery-origin.md) under
[EPIC-00013](../epics/00013-EPIC.md).

## Use Cases and Requirements

| Actor and trigger | Commands | Queries | Events | Observable result |
| --- | --- | --- | --- | --- |
| An authorized administrator corrects a pending registration sent to a wrong address | Distinct administrator registration-recovery command, not `CorrectPendingInvitation` | Authoritative User, current activation and email-claim reads inside the transaction; existing protected User/delivery-status reads for operator inspection | Safe recovery fact only after commit; audit within the transaction | Replace the canonical destination safely, invalidate predecessor activation authority and stage a fresh registration-origin grant/delivery; retain the same pending User ID and Role set. |
| An authorized administrator reissues after activation expiry at the same address | Same recovery use case with a fresh activation generation; exact command split is later design | Current User/grant/expiry read, never a preflight availability claim | Safe post-commit recovery fact and audit | Issue a fresh one-time registration activation to the same pending User and email; old token/delivery cannot activate or gain a second send. Expired delivery is not simply retried as a valid token. |
| A consumer/admin requests recovery for a private invitation, active/deleted/disabled User, an incorrect actor, or stale generation | The appropriate private-invitation path remains separate; registration recovery rejects ineligible requests | Protected reads only, with final authoritative revalidation before mutation | Failure notification/rethrow; no recovery success fact | No anonymous takeover, User replacement, Role reset, unrelated reservation change, partial grant/delivery or mistaken origin switch. |

The existing private-invitation correction operation must remain private-invitation-specific, rejecting a
registration rather than silently correcting it. An administrator restoring a deleted User to pending starts a
**new private invitation** even if that User originally registered; this is not recovery of an old registration.
The same User and Roles survive either registration-recovery variant. Recovery never reapplies newly configured
registration defaults.

## Security, Validation and Failure

- Enforce administrator authorization inside the trusted package operation for the exact target, separate from
  user-supplied actor identity; consumers also protect every exposed dispatch and query path. Design the precise
  authorization port and safe failure surface during TASK decomposition. No public/anonymous recovery command,
  inferred right to administer from possession of an email or automatic resend on duplicate registration.
- Validate command identity, destination email and expected current pending-registration generation. Wrong-address
  replacement respects canonical-email claims/live reservations; same-email reissue is allowed only for the same
  eligible pending User after expiry. The exact freshness/expiry, destination and state checks occur under the
  transaction. Query results, where needed to inspect eligibility, must be protected and secret-free; reuse
  `GetUserById`/`FindInvitationDeliveryStatus` when sufficient and justify any additional read-only query rather
  than exposing a public email-existence check. No query mutation or success event.
- Commit corrected identity (if changed), predecessor invalidation, fresh grant, encrypted registration-origin
  delivery and audit atomically, with complete-state conflict fencing against activation, correction, competing
  resend/reissue, account deletion/restoration and address reservations. Reject inconsistent or stale state rather
  than reviving authority. No second User, Role reassignment, orphan grant or repeat-success publication after
  unchanged work. Delivery/provider work remains recoverable and after commit.
- Preserve normal command failure/rethrow and post-commit notification behavior. A failed commit leaves predecessor
  authoritative, including its previous address/Role set; a committed recovery persists despite notification
  failure. Do not infer rollback from response loss; resolve by current User/grant/delivery state.

## Acceptance Evidence

- [ ] Prove wrong-address correction and expired-same-email reissue independently with the same pending User and
      exact Role set; old activation authority and stale worker outcomes cannot win after replacement.
- [ ] Prove admin admission and consumer dispatch protection requirements, ineligible User states/origins,
      incorrect actor, non-expired same-email reissue, canonical-email collisions/live reservations and the
      invitation-specific correction guard all reject without partial writes or registration success facts.
- [ ] Exercise controlled races for recovery versus activation, resend, correction, deletion/restoration and
      another recovery in both winner orders. Verify rollback, restart, uncertain-commit/response-loss resolution,
      post-commit event failure, audit and durable provider origin across reissue and retry/restart.
- [ ] Verify safe command/query/event serialization, handler registration, documentation and public schema updates,
      focused/full implementation gate and explicit consumer-owned persistence/authorization/provider qualification
      gaps when work is authorized. Model package behavior without claiming live consumer mail or database proof.

## Dependencies, Exclusions and Handoff

Requires the origin contract of [TICKET-00021](00021-TICKET.md) and the pending registration created by
[TICKET-00022](00022-TICKET.md). This is a complete administrator recovery journey, not a generic new account
lifecycle or a layer-only implementation slice. Excludes public cancellation, broad non-pending recovery,
anonymous retry/resend, retroactive Role changes, package mail templates/HTTP/SQL and a new package enablement
gate. Consumer integration, release and deployment remain independent. Follow
[ADR 0011](../adr/0011-pre-v1-current-contract-only.md).

John approved one complete administrator recovery slice, now [TASK-00075](../tasks/00075-TASK.md), blocked by
[TASK-00074](../tasks/00074-TASK.md), which itself depends on the durable origin contract in TASK-00073.
It owns both correction and expired-same-email reissue with one authorization/recovery proof. Exact API and
adapter contracts are bounded TASK design, not permission to widen product scope. All three EPIC-00013 TICKETs
now have complete approved TASK plans; Board-based execution selection and authorization remain separate.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 75 | [TASK-00075](../tasks/00075-TASK.md) | Recover pending registrations with authorized correction and reissue | in-progress |
## Progress

John approved the three-area TICKET split for EPIC-00013 and this TICKET's single TASK-00075 plan. TASK-00074
is integrated via PR #122; John's later explicit Build invocation authorizes TASK-00075 in the main checkout.
Engineer implementation is in progress on `feature/task-00075-registration-recovery` from `8119bd84`.
The [recovery contract](../../docs/registration-recovery.md) describes both variants, exact-target authorization,
complete-state transaction-duration fencing, failure/uncertainty resolution and controlled package evidence.
Independent Review and applicable nonvisual QA remain pending; this TICKET is not done. Actual consumer database,
authorization, templates, physical restart and public-security qualification remain unexecuted.
