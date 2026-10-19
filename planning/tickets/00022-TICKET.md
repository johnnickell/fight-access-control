---
id: TICKET-00022
epic: EPIC-00013
title: Register a pending User with configured default Roles
status: in-progress
---

# Register a pending User with configured default Roles

## Problem and Outcome

A consumer may choose to expose self-registration, but today's private-invitation command is not an anonymous
registration entry point and creates no default Roles atomically. Supply a distinct package registration command
and handler that leave a pending User with a complete consumer-configured Role set, one-time activation authority,
registration-origin recoverable delivery and audit, or leave none of these effects. The consumer decides if/where
to call it. This requirement implements [WF-026](../wayfinder/tickets/WF-026-registration-admission-and-default-authority.md)
and [WF-028](../wayfinder/tickets/WF-028-public-registration-safety-boundary.md) within
[EPIC-00013](../epics/00013-EPIC.md).

## Use Cases and Requirements

| Actor and trigger | Commands | Queries | Events | Observable result |
| --- | --- | --- | --- | --- |
| A consumer-admitted new User requests registration | New distinct registration command/handler using email and needed identity/provenance; **no** request-selected Roles | Existing protected `GetUserById` and delivery-status reads can inspect the result; no new public lookup by email | Safe registration-specific success fact only after a complete commit; audit in the same transaction | One canonical-email-unique pending User, all configured default Roles, activation grant and encrypted registration-origin delivery commit together. Existing password-setting activation remains the final activation step, not a registration-time password. |
| A duplicate canonical address or live reservation is submitted | Same registration command | Authoritative uniqueness is checked at the write boundary, not an unsafe availability preflight | Safe failure notification and original exception; no new success fact | Registration rejects for pending, active, disabled or deleted identity and live reservations; no second User, activation, delivery or audit of successful registration. |
| Consumer configuration or a Role reference is invalid or changes concurrently | Same registration command | Authoritative Role resolution by name inside the operation | No success fact; normal command failure/rethrow | Nonempty defaults are mandatory; any missing name, forbidden `ROLE_SUPER_ADMIN` or invalid/concurrently changed Role rejects *all* new registration effects. |
| Delivery scheduling/publication fails after registration commit | Existing direct delivery, due-work discovery and provider invocation | Existing safe delivery-status reads where authorized | Success publication/failure reporting follows current package ordering | Committed User/Role/grant/delivery/audit remain authoritative and recoverable on restart; failure must not be described as rollback or as proof mail was sent. |

The handler receives a consumer-supplied nonempty list of `RoleName` strings at construction and resolves current
Roles by name for each registration. Do not assume `ROLE_USER` is present or classify all other consumer Roles as
forbidden. Persist assignments with the pending User; later configuration changes do not retroactively reassign
that User. Reuse the activation lifecycle and the durable origin contract of
[TICKET-00021](00021-TICKET.md), not the private-invitation command as an anonymous caller API.

## Security, Validation and Failure

- The consumer protects every exposed entry point, controls enablement (including installation database settings),
  and selects its public response. AccessControl adds no installation-enable check, throttling/challenge, abuse log,
  HTTP response mapping or package generic acknowledgement. Duplicate rejection is internal and unchanged; the
  consumer evaluates its own public account-enumeration and abuse risk. A supplied actor/provenance field, if any,
  is not authorization and must not falsely attribute an authenticated User to an anonymous registrant.
- Validate email using the current canonical-email contract, default Role names using `RoleName` and repository
  authority, and all required command fields/serialization. No raw activation credential, password or untrusted
  Role choice in a serializable public command, query result, failure event or audit evidence. Do not introduce an
  email-availability query that reveals existence or duplicates the repository's atomic admission check.
- Repository identity claims and current Role references must be safe through commit, including interleaved
  removal/replacement. The `TransactionalUnitOfWork` encompasses pending User, complete Role set, grant, delivery
  and audit. On any pre-commit failure, including partial storage failure/CAS loss, none survive. Safe success is
  published only after commit; preserve normal `CommandFailedEvent`/original failure rethrow and do not assert
  that provider/notification failure undoes a committed registration.
- Exact registration DTO, success-event/audit classification, Role-reference fencing and whether an additional
  protected operational query is needed belong to subsequent design; existing protected User/delivery queries may
  suffice. New queries, if justified, must remain safe, read-only and consumer-authorized rather than become a
  public identity probe.

## Acceptance Evidence

- [ ] Verify required/nonempty configured Roles, missing/forbidden/invalid names, multiple default Roles,
      no caller-selected Roles, and pending User authorization state before versus after one-time activation.
- [ ] Verify atomic uniqueness for claimed canonical emails across all User states and live reservations;
      duplicate failure emits no new activation/delivery/success fact. Prove concurrent Role change/removal and
      all-or-nothing identity/Role/grant/delivery/audit outcomes under both winner orders using controlled races.
- [ ] Verify write/commit failure, uncertain-commit/response loss, post-commit notification/provider faults,
      recoverable scheduler dispatch and no secret-bearing public evidence. Test current message round trips,
      handler registration, audit and post-commit fact ordering.
- [ ] Update public usage/composition guidance and affected schemas/README/CONTEXT/changelog. Run focused
      checks and the full package implementation gate when work is authorized. Separate modeled package proof
      from unexecuted real consumer transaction/uniqueness/Role-reference, mail, runtime and public abuse evidence.

## Dependencies, Exclusions and Handoff

Depends on TICKET-00021's delivery-origin contract; creation and delivery can be implemented as coordinated
slices, but the registration journey is not accepted until both work together. Existing invitation policy and
normal activation remain intact. Excludes a package HTTP page, production Adapter/SQL, template renderer, public
registration cancellation, anonymous retry/takeover, retroactive Role reassignment, historical-reader/migration
code and enabling registration in Agent OS/starters. Follow
[ADR 0011](../adr/0011-pre-v1-current-contract-only.md).

John approved one complete registration slice, now [TASK-00074](../tasks/00074-TASK.md), blocked by
[TASK-00073](../tasks/00073-TASK.md)'s durable origin/provider contract. It covers the whole package registration
journey, including creation-time Role-reference fencing and activation/delivery proof; no separate layer-only
TASK is needed. The subsequent [TASK-00075](../tasks/00075-TASK.md) plan completes TICKET-00023 decomposition;
all planning phases are accepted under [EPIC-00013](../epics/00013-EPIC.md#progress). Consult the
[Board](../tasks/BOARD.md) for execution selection, preserving TASK-00074's now-satisfied TASK-00073 dependency.
This remains v1.0.0-track work, not a v0.6.0 release prerequisite. Planning alone did not authorize implementation;
John's later Build invocation authorized TASK-00074 execution, not consumer enablement.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 74 | [TASK-00074](../tasks/00074-TASK.md) | Register a pending User atomically with default Roles | in-progress |
## Progress

John approved the complete decomposition and subsequently invoked Build for this TICKET's sole
[TASK-00074](../tasks/00074-TASK.md), after TASK-00073's independent acceptance and integration through PR #121.
Initial implementation passed local gates, but independent Review returned REVISE at `8b19ff7` for F1/F2 in the
composed delivery worker: unsafe diagnostic publication and original-throwable replacement by notification failure.
Engineer repair cycle 1 reproduced both with failing regressions, then bounded the worker's failure text and made
failure notification best effort. Fresh focused **696 tests / 9546 assertions** and full `./bin/build`
**2199 tests / 41974 assertions**, exact **7131/7131** owned statements, pass without final warnings/skips.
The TASK owns complete red/green, runtime/dependency, gate and content-equivalence evidence under
`.runs/logs/TASK-00074/repair-1/`; no dependency package drift or gate weakening occurred.
The [registration guide](../../docs/user-registration.md) records the current package composition and evidence map.
Parent acceptance-evidence checkboxes remain pending independent acceptance, not missing Engineer implementation.
Fresh independent technical re-review and behavioral QA remain outstanding; the canonical REVISE report is unchanged,
so neither this TICKET nor TASK-00074 is done.
TASK-00075 retains administrator recovery; all actual consumer database/mail/public-security qualification and
consumer enablement remain unexecuted.
