---
id: TICKET-00044
epic: EPIC-00017
title: Audit User account and credential journeys
status: ready-for-agent
---

# Audit User account and credential journeys

## Problem and Outcome

User auditing is uneven across administrative/self-service paths, delivery expiry and synchronous operations.
Under [EPIC-00017](../epics/00017-EPIC.md), cover meaningful account, credential and session outcomes explicitly
inside their owning transactions. Consumers can intentionally omit audit even where it was previously mandatory;
when configured, evidence remains required and atomic without weakening concealment or recovery behavior.

## Use Cases and Contracts

| Actor and trigger | Commands / synchronous operations | Required outcome |
| --- | --- | --- |
| User, anonymous initiator or authorized administrator starts/changes an account | InvitePendingUser, RegisterUser, CorrectPendingInvitation, RecoverPendingRegistration, EnableUser, DisableUser, DeleteUser, RestoreUser | Evidence for meaningful committed lifecycle outcomes, preserving origin and existing target authorization. |
| Trusted worker/admin delivers or manages invitations | DeliverUserInvitation, ExpireInvitationDelivery, ResendInvitationDelivery, RetryInvitationDelivery | Evidence for committed delivery, failure, expiry or replacement outcomes, not polls/temporary claims. |
| Anonymous initiator requests reset; worker delivers/expires work | RequestPasswordReset, DeliverPasswordReset, ExpirePasswordResetDelivery | Explicit anonymous/request provenance, eligible committed outcomes and unchanged ineligible-request concealment. |
| User/admin changes email; worker delivers/expires work | RequestEmailChange, CancelEmailChange, DeliverEmailChange, ExpireEmailChange | Self-service and administrative meaningful outcomes, not only today's conditional administrative audit. |
| User/admin revokes a session | RevokeSession | Meaningful self-service/admin revocation evidence under retained ownership rules. |
| User activates/authenticates or changes credentials | Synchronous activation, login, refresh, logout, password change/reset and email confirmation | Secret-free evidence of meaningful committed outcomes; credentials remain sensitive non-serializable inputs/results. |

The Command inventory is 8 + 4 + 3 + 4 + 1 = 20 handlers. User Role assignment/removal belongs to TICKET-00045.
Existing success/failure facts retain their publication behavior; no new query/reporting API or permission grant is
required. Query/status reads do not become mutations merely because auditing is enabled.

## Requirements and Validation

- Apply [TICKET-00043](00043-TICKET.md)'s precise evidence, optional last repository injection and actor rules in
  every owned handler/synchronous owner, including existing mandatory writers and administrative-only variants.
- Preserve existing business-required actor and target checks. Supply explicit optional provenance where absent;
  reject missing attribution before the first mutation requiring enabled evidence. Deliberately anonymous
  requests establish AnonymousId without pretending the target User is authenticated. Autonomous expiry/maintenance
  uses explicit SystemId; workers preserve original User/Agent provenance for admitted operations.
- Omitted/null audit storage makes no durability promise and imposes no new audit-only actor obligation. Configured
  write failure must fail required pre-commit work, not silently downgrade to disabled auditing.
- Add typed evidence within the actual mutation transaction/connection, with trusted outcome time, applicable
  revisions/relationships and real correlation. Keep invariant decisions with their aggregate owner; do not
  reconstruct Domain policy or manufacture records from subscribers/intercepted repository calls.
- Preserve eligibility, generic public responses, anti-enumeration, throttling/dummy-hash behavior, grant single-use,
  session rotation/revocation, target ownership, revision/CAS checks, invitation origin and pending-registration
  distinctions. Ineligible/no-work/no-change paths create no committed-mutation evidence or new disclosure.
- Evidence includes meaningful delivery/expiry and committed failed-delivery transitions. Avoid duplicate records
  from lower-level collaborators, claims, polls or internal helper writes. Rejected/rolled-back attempts do not
  become committed-outcome evidence; separate consumer diagnostic telemetry remains outside this contract. A
  meaningful transition actually committed before outward refusal, such as terminal-replay session revocation,
  still receives outcome evidence rather than being mislabeled a rejected-attempt log.
- Audit failure rolls back staged transactional changes, including correlated User/grant/session/delivery writes.
  Existing external send/claim/receipt recovery remains truthful: a database rollback cannot unsend a delivery.
  Preserve uncertain commits, primary failures and existing post-commit failure propagation, not the separate
  Agent issuance warning exception.
- Keep raw credentials, tokens, hashes, ciphertext, raw messages and diagnostic exceptions out of evidence and
  serializable Commands. Admit bounded safe context explicitly, including reasons; do not log evidence wholesale.

## Dependencies and Existing Work

Consume TICKET-00043's shared contracts; direct necessary caller updates must ship in complete current-contract
paths. Family evidence/admission/coverage acceptance is owned here. [TICKET-00035](00035-TICKET.md) retains User
transition/synchronous-capability redesign; use the current implemented owner and coordinate overlapping edits
without duplicating that redesign or imposing a blanket wait. TICKET-00037/00040 retain typing ownership.

Feed the complete outcome matrix to TICKET-00048, but keep focused checks/docs/full-gate and independent acceptance
with each slice. Refresh the baseline inventory at TASK planning and assign actual supplier edges then. Obtain any
applicable approved private operation brief through its authorized channel before implementation/review; do not
infer its availability or copy restricted criteria into public planning.

## Acceptance Evidence

- [ ] All 20 handlers and every listed synchronous journey have explicit coverage, including conditional admin/
  self-service, anonymous, expiry, no-change and ineligible paths; no family is sampled away.
- [ ] Enabled/disabled constructor composition and actor admission work without fallback identities, transport
  lookup, new permissions or newly required audit-only actor input for disabled/pure no-change execution.
- [ ] Evidence has correct typed subject/origin/time/revision/correlation/safe context; no credential, raw payload,
  unnecessary personal data or rejection diagnostic appears in outcome evidence or public/log response data.
- [ ] Persisted state assertions prove mutation/evidence rollback on pre-commit failure and concurrent loss,
  committed evidence despite post-commit failure, and unchanged uncertain/external-effect recovery semantics.
- [ ] Generic ineligible responses, target authorization, grant/session safety and meaningful delivery/expiry/failure
  transitions remain correct; temporary claims/no-work helpers create no duplicate mutation evidence.
- [ ] Each executable slice has focused/regression-first checks as applicable, canonical full gate/exact owned
  statement coverage, current callers/docs, complete local evidence and independent Review/behavioral QA.

## Exclusions

User capability redesign, business authorization changes, new Queries/permissions, secret-bearing Commands,
consumer persistence/transports, durable rejected-attempt storage, new delivery/replay/outbox machinery,
compatibility/data migration, release and deployment. Agent credential work and User Role membership are separately owned.

## Approved TASK Plan and Coverage

John approved the complete five-TASK split, now written as independently reviewable use-case slices:

| TASK | Order | Owned outcome | Baseline coverage | Blocker |
| --- | --- | --- | --- | --- |
| [TASK-00123](../tasks/00123-TASK.md) | 403 | User creation, pending recovery/correction and lifecycle administration | Eight handlers. | TASK-00122 |
| [TASK-00124](../tasks/00124-TASK.md) | 404 | Invitation delivery, resend/retry and expiry | Four handlers. | TASK-00122 |
| [TASK-00125](../tasks/00125-TASK.md) | 405 | Password-reset request, delivery and expiry | Three handlers; synchronous password operations excluded. | TASK-00122 |
| [TASK-00126](../tasks/00126-TASK.md) | 406 | Email-change request, cancellation, delivery and expiry | Four handlers; synchronous confirmation excluded. | TASK-00122 |
| [TASK-00127](../tasks/00127-TASK.md) | 407 | Synchronous User security and session operations | RevokeSession plus activate/login/refresh/logout/changePassword/resetPassword/confirmEmail. | TASK-00122 |

The allocation covers 8 + 4 + 3 + 4 + 1 = all 20 owned handlers and all seven synchronous operations. This is a
planning manifest, not executed behavioral/transaction/consumer qualification. Refresh source ownership at execution.
TASK-00127 is the sole synchronous optional-wiring/outcome owner, including password/email paths. Follow narrower
capability owners if TASK-00112 has already replaced AuthenticationService; do not retain or recreate that broad graph.

Each task consumes TASK-00122's shared contract (and inherits TASK-00121 transitively). No mutual dependency between
these five audit slices is demonstrated; existing workflows already operate, so another family's optional-audit
extension is not a prerequisite. Orders 403–407 follow existing work without renumbering it. Architecture/typing
source overlap is coordination, not a blanket blocker or existing TASK scope expansion. Each slice owns complete
necessary inputs/callers/tests/docs, canonical gate and independent acceptance; TICKET-00048's final reconciliation
does not defer those obligations. Choose the implementation checkout explicitly before execution.

A benign refresh conflict is no-change and gets no mutation evidence. Terminal replay can commit session revocation
before returning an outward refusal; TASK-00127 records that actual security transition without recording a successful
refresh or inventing rejected-attempt storage. Concurrent winner/helper no-ops must not duplicate mutation evidence.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 403 | [TASK-00123](../tasks/00123-TASK.md) | Audit User creation, recovery and administration | done |
| 404 | [TASK-00124](../tasks/00124-TASK.md) | Audit invitation delivery and expiry | done |
| 405 | [TASK-00125](../tasks/00125-TASK.md) | Audit password-reset requests, delivery and expiry | ready-for-agent |
| 406 | [TASK-00126](../tasks/00126-TASK.md) | Audit email-change requests, cancellation, delivery and expiry | done |
| 407 | [TASK-00127](../tasks/00127-TASK.md) | Audit synchronous User security and session operations | ready-for-agent |
## Decisions and Progress

John approved this requirement in EPIC-00017's six-TICKET split, then the complete five-TASK plan now written as
TASK-00123 through TASK-00127. Each is ready-for-agent with TASK-00122 as its only direct blocker; this is planning
readiness, not execution/acceptance. No source/dependency/consumer change, product build, new branch/worktree,
commit/publication, independent implementation acceptance or release is claimed by writing the records.

At this five-TASK checkpoint, planning generation/read-only validation passed (191 records, 65 active), plus
documentation links and diff/record whitespace checks. A source-name inventory accounts for 20/20 owned handlers without overlap/omission and all seven
synchronous methods in TASK-00127. This is planning coverage, not a product/consumer test; no product build was run.
The Board still selects TASK-00107 first and earlier TASK scope/order/blockers remain unchanged.

TICKET-00045's subsequent five-TASK plan is approved/written as TASK-00153–00157, each blocked only by TASK-00122;
User membership remains separate from this ticket's lifecycle/synchronous ownership.

TICKET-00046's subsequent four-TASK plan is approved/written as TASK-00158–00161, each blocked only by TASK-00122.
TICKET-00047's subsequent four-TASK plan is approved/written as TASK-00162–00165, separate from User-family insertion.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00016's
accepted TASK split is complete, not implemented/qualified; added Agent conversion and real supplier qualification
remain separate. Finish selected planning before separate Board-based implementation selection.
