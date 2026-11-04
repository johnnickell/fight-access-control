---
id: TICKET-00035
epic: EPIC-00015
title: Encapsulate User authentication transitions and synchronous use cases
status: done
---

# Encapsulate User authentication transitions and synchronous use cases

## Problem and Outcome

Authentication callers must mutate User and separately advance its authority revision, while AuthenticationService
combines seven operations and their large dependency graph. Make aggregate transitions complete and synchronous
use-case interfaces cohesive without weakening authentication, recovery or persistence under
[EPIC-00015](../epics/00015-EPIC.md).

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| User activates or logs in | Synchronous sensitive call; UserActivated/UserLoggedIn | Correct User successor and coupled refresh-session insertion commit before publication/token return. |
| User refreshes or logs out | Synchronous sensitive call; existing logout fact | Same rotation/conflict/replay or current-session revocation semantics. |
| User changes/resets password or confirms email | Synchronous sensitive call; existing password/email facts | Same grant consumption, User authority changes, cancellation/revocation and audit atomically. |
| Stale writer, validation, storage or publisher fails | Existing redacted failure evidence | Preserve actual rollback/uncertainty/committed-state distinction and primary failure. |

## Requirements and Policy Amendment

- TASK-00111 makes each User transition own its related authentication-authority revision exactly once. Keep
  authenticationVersion distinct from the persistence revision. Successful login serializes authority even when
  rehash is unnecessary; use a meaningful transition, not public increment/touch bookkeeping or a flags bag.
- Retain complete expected-state CAS, coupled User/session insertion and all transaction-duration reference,
  authentication, identity and recovery fences. Better aggregate encapsulation never replaces database concurrency.
- TASK-00112 replaces the broad public AuthenticationService graph with cohesive authentication/token issuance,
  refresh-session lifecycle, password-change/reset and email-confirmation capabilities. Each owns its actual
  synchronous coordination and only necessary dependencies; no retained broad forwarding facade or one helper
  class per private method. Concrete class names follow the capability design within these boundaries.
- This explicitly amends the profile/ADR 0003 single-AuthenticationService placement requirement. It does not
  amend synchronous handling: raw secrets remain SensitiveParameter inputs and non-serializable, never message DTOs.
  Existing non-sensitive Command/Query dispatch still uses its configured bus, not direct handler calls.
- Keep state-based decisions with User/grants/sessions. Preserve existing safe result shapes, token lifetimes,
  dummy-hash/throttle behavior, replay/conflict outcomes, single-use grants, audit and post-commit publication policy.
  Notification failure cannot replace the primary fault or imply rollback after commit.
- Apply ADR 0011: update all owned callers/tests/current examples directly, with no deprecated service alias,
  hidden legacy mode or previous-iteration data support. Preserve current-contract hydration and restoration safety.

## Dependencies and Evidence

TASK-00111 (order 201) precedes TASK-00112 (202), whose design consumes complete User transitions. No MCP, native-
framework or Common future-release blocker is introduced. These improvements follow the existing feature frontier
and are not prerequisites for Laravel, CodeIgniter or unrelated family integrations.

Use current public operation-outcome/recovery guides and applicable authorized private requirements before
changing those contracts. Do not copy restricted briefs into planning/public evidence or classify an unavailable
brief as permission to weaken a rule. Implementation still has its existing prerequisite checks.

## Acceptance Evidence

- [ ] A password/email/activation transition needs no separate caller revision increment; the successful-login
  path advances required authority exactly once even without rehash, and rejected transitions leave state unchanged.
- [ ] Meaningful before/after callers show fewer setup/sequencing obligations while keeping secret input,
  domain choices, failures, transaction ownership and effects explicit.
- [ ] Stale login/reset, grant consumption, account lifecycle and session-revocation races retain authoritative
  outcomes; late audit failure rolls back staged writes and notification faults preserve the primary outcome.
- [ ] Real synchronous calls return existing safe results and emit only existing redacted messages/facts after the
  required commit; no secret-bearing message/facade or new delivery guarantee appears.
- [ ] Current consumer composition/docs, focused domain/use-case/race tests, full gate/exact coverage and independent
  Review/applicable QA cover each implemented slice without claiming actual consumer DB/crypto qualification.

## Exclusions

New authentication policy, changed grants/token wire formats, secret-bearing CQRS, asynchronous authentication,
UserRepository/UoW replacement, compatibility code, consumer deployment and unrelated managed-policy repairs.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 201 | [TASK-00111](../tasks/00111-TASK.md) | Make User transitions own authentication-authority revisions | done |
| 202 | [TASK-00112](../tasks/00112-TASK.md) | Separate cohesive synchronous authentication capabilities | done |
## Decisions and Progress

John approved the complete two-TASK split. Scope is an explicit pre-v1 API redesign, not a claim that existing code
violated its accepted synchronous-service exception. Planning only; source and verification are unchanged.

Next: `/skill:next` for Board-based selection; no automatic execution or release.
