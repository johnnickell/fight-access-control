---
id: TICKET-00021
epic: EPIC-00013
title: Preserve registration and private-invitation delivery origin
status: done
---

# Preserve registration and private-invitation delivery origin

## Problem and Outcome

An activation credential currently has purpose `activation`, with no durable way for a consumer's mail provider to
select registration versus private-invitation copy after retry, resend or restart. Add a non-sensitive, immutable
origin to recoverable activation delivery and its provider invocation. The provider can choose its own template;
AccessControl does not render, configure or send either message. This is the shared delivery prerequisite for
[EPIC-00013](../epics/00013-EPIC.md), governed by
[WF-027](../wayfinder/tickets/WF-027-registration-delivery-origin.md).

## Use Cases and Requirements

| Actor and trigger | Commands | Queries | Events | Observable result |
| --- | --- | --- | --- | --- |
| Consumer invokes a private invitation or the new registration use case | Existing invitation or planned registration command, respectively | N/A for creating delivery | Existing invitation publication and planned registration publication occur only after commit | Each new activation delivery has the correct durable origin; the consumer provider receives that origin with the delivery identity, destination and one-time credential. |
| Consumer immediately dispatches or restarts a worker for due delivery | Existing direct delivery and due-work query/handler | Existing due and safe delivery-status reads, as needed; no new public identity lookup | No new event is needed to infer origin | Claimed work retains the stored origin through provider invocation, independent of the publication event or caller route. Provider retry remains at-least-once with the same delivery identity. |
| An authorized resend or private-invitation correction replaces a grant | Existing resend/correction operations | Authoritative grant and User reads inside the relevant operation | Existing facts after real commit; no new event solely to select copy | A registration-origin resend retains registration origin; private-invitation replacements remain private. Origin cannot be supplied by the caller to reclassify another User's delivery. |
| An administrator restores a deleted User to pending activation | Existing restore operation | Authoritative User/grant read | Existing restore/publication semantics | The **new** activation journey is private invitation, even if an earlier journey was registration. |

The current `activation` purpose remains the credential purpose, not the template selector. The origin is a
persisted business fact on the current activation generation; replacement keeps or intentionally establishes the
correct origin without relying on HTTP route, transient event, provider response or historical User origin. This
TICKET supplies the origin contract; registration creation is owned by
[TICKET-00022](00022-TICKET.md), and the distinct administrator registration-recovery policy by
[TICKET-00023](00023-TICKET.md).

## Security, Validation and Failure

- Validate the origin on creation/hydration and keep it stable for the exact delivery generation through claim,
  outcome, retry, restart and resend. Fail closed on unsupported/corrupt current state; do not silently default a
  registration to invitation or reclassify a private invitation through caller input. Exact representation and
  replacement fencing belong to TASK design. Scope the origin to activation work without changing password-reset
  or email-change semantics.
- Origin grants no authority. Consumers authorize all exposed dispatch/administration paths, including workers and
  direct commands; existing package ownership/generation/claim fences still apply. Provider adapters must recognize
  the current invocation contract and use their own templates; idempotency remains the delivery ID.
- Preserve recoverable post-commit dispatch and original command failure/rethrow semantics. Failure before commit
  leaves no half-updated grant/delivery; post-commit event/provider faults do not roll back committed work or imply
  delivery/activation. Do not add reliable-event publication or a template service.
- Public safe status reads need not reveal template copy or credentials. Existing `FindInvitationDeliveryStatus` and
  `FindDueCredentialDeliveries` provide operational reads; add a protected safe query only if downstream design
  demonstrates one is necessary, not as an anonymous existence oracle.

## Acceptance Evidence

- [ ] Prove both origins at provider invocation for immediate dispatch, retry/reclaimed claim, resend, and scheduler
      discovery after restart, using persisted generation rather than success-event metadata.
- [ ] Prove invitation correction and ordinary invitation resend stay private, registration resend stays
      registration, deleted-to-pending restore starts private, and a stale/replaced grant cannot overwrite the
      current origin or invoke the provider with obsolete authority.
- [ ] Prove validation/serialized round trips and safe handling of invalid persisted origin, storage rollback and
      post-commit publication/provider failure without false rollback or a secret in public status/failure evidence.
- [ ] Update provider/worker and repository integration guidance, affected public schemas and tests. Include
      package-bindable contract proof, focused tests and the full implementation gate when work is authorized.
      Identify, but do not claim, separate actual-consumer verification that existing private invitation templates
      are configurable and remain distinct from registration templates across retries/restarts.

## Dependencies, Exclusions and Handoff

This is a prerequisite contract for TICKET-00022/00023; its cross-cutting acceptance is not a separate
presentation-only or documentation-only implementation requirement. Consumer persistence adapters, mail templates,
mail transport, worker deployment and real-database proof remain consumer-owned. No public registration endpoint,
installation enablement gate, new reset/email-change policy, historical reader/migration or default activation
origin for old contracts is authorized; follow [ADR 0011](../adr/0011-pre-v1-current-contract-only.md).

John approved one complete implementation slice, now [TASK-00073](../tasks/00073-TASK.md), with no unfinished
TASK blocker. It covers the full origin/provider path and contract-level proof; the actual registration command
belongs to TICKET-00022. The subsequent [TASK-00074](../tasks/00074-TASK.md) and
[TASK-00075](../tasks/00075-TASK.md) plans complete TICKET-00022/00023 decomposition. All planning phases are
accepted under [EPIC-00013](../epics/00013-EPIC.md#progress); consult the [Board](../tasks/BOARD.md) for execution
selection. This is planned v1.0.0 work, not a v0.6.0 release prerequisite or implementation/PR authorization.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 73 | [TASK-00073](../tasks/00073-TASK.md) | Carry activation-delivery origin through provider recovery | done |
## Progress

John approved the three-area TICKET split for EPIC-00013, then one cohesive TASK per area in dependency order
[TASK-00073](../tasks/00073-TASK.md) → [TASK-00074](../tasks/00074-TASK.md) →
[TASK-00075](../tasks/00075-TASK.md). Decomposition is complete; the [Board](../tasks/BOARD.md) owns execution
selection. TASK-00073 is done for independently accepted package implementation and required behavioral QA: Review ACCEPT and
QA PASS cover candidate `899dcbd9abdb22df1ca359afaa173789190fc780` against unchanged base `2edbd347`. Its
administrative Landing closeout records that accepted candidate; publication/merge remain separate. Actual consumer
SQL, template and runtime qualification remains unexecuted. The TASK owns criterion-to-test evidence and the ignored
Work/Land handoffs own execution and delivery receipts; this TICKET closes automatically with its sole terminal child.
