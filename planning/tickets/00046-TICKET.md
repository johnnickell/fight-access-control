---
id: TICKET-00046
epic: EPIC-00017
title: Audit Feature and managed-policy changes
status: ready-for-agent
---

# Audit Feature and managed-policy changes

## Problem and Outcome

Feature administration/provisioning and managed-policy reconciliation currently have no explicit package audit
insertion. Under [EPIC-00017](../epics/00017-EPIC.md), record meaningful changed subjects atomically with their
state, using explicit provenance and existing transactions. Optional opt-out is intentional and carries no durable-
evidence guarantee; batch execution must not produce noisy records for unchanged definitions or incidental writes.

## Use Cases and Contracts

| Caller and trigger | Commands | Required outcome |
| --- | --- | --- |
| Authorized caller creates a Feature | CreateFeature | Typed Feature creation evidence when auditing is enabled. |
| Trusted bootstrap discovers/provisions code-defined Features | ProvisionFeatures | Explicit SystemId at the command boundary; changed subjects share real command correlation. |
| Authorized caller changes Feature availability or Permission | SetFeatureStatus, SetFeaturePermission | Meaningful Feature/Permission relationship changes, with existing validation and revisions preserved. |
| Authorized caller retires a Feature | RemoveFeature | Truthful removal evidence and retained managed/reference retirement protection. |
| Authorized caller reconciles managed policy | ReconcileManagedPolicy | Per meaningfully changed Role/Permission evidence within the actual reconciliation boundary; current Feature references are guards, not Feature mutations. |

The inventory is five Feature handlers plus one managed-policy handler. Reuse existing Commands and success/failure
facts; do not add audit Queries, new permission grants or mutation to availability/status reads. System attribution
is provenance, not new bootstrap/administration authority.

## Requirements and Validation

- Apply [TICKET-00043](00043-TICKET.md)'s shared model, optional last audit repository and admission policy. Use
  direct typed actorId inputs, reusing existing actor data and adding optional provenance where absent. Disabled
  auditing requires no new audit-only input; enabled work rejects missing attribution before its first mutation
  requiring evidence. Discovery/validated no-change may finish without audit-only attribution.
- ProvisionFeatures accepts SystemId::fromString(Uuid::NIL) once per provisioning Command. No per-Feature actor
  declaration, login, system account or additional human approval is introduced. Preserve a real originating User/
  Agent where applicable rather than overwriting attribution merely because execution runs in a worker.
- Explicitly construct/add evidence inside the actual handler-owned mutation transaction/connection. Configured
  evidence failure aborts staged required changes; never fall back to disabled behavior. Preserve authoritative
  revision/reference checks and aggregate-owned invariants rather than reverse-engineering facts from Events.
- For ProvisionFeatures and ReconcileManagedPolicy, record each meaningfully changed subject with shared real
  message/operation correlation. Unchanged subjects produce no mutation record; an all-existing/no-change Feature
  provisioning pass does not require audit-only actor input. Do not count every repository write/helper step as an
  independently meaningful outcome or invent universal replay semantics.
- Evidence uses typed Feature/Role/Permission subjects and relationships, trusted outcome times and actual
  applicable revisions, including Role incarnation. Creation/removal never fabricate nonexistent predecessor/
  successor state. Package-defined action and bounded admitted context replace arbitrary payload capture.
- Preserve code discovery, canonical naming, existence versus availability versus authorization distinctions,
  valid default Permission/eligibility, managed definitions/protected policy, reference/CAS checks and safe Feature
  retirement. Auditing cannot grant permissions or relax reconciliation admission/fences.
- Preserve full batch atomicity, primary fault and uncertain-commit treatment. Concurrent losing work rolls back
  staged evidence; post-commit event failure leaves state/evidence committed with existing propagation. No durable
  rejected-attempt store, post-commit evidence manufacture or generic transaction interception is added.
- Update directly affected message serialization, current callers and composition examples without legacy modes.
  Secret/raw payload/diagnostic capture is excluded, including in free-text reasons or proposed plan snapshots.

## Dependencies and Existing Work

Consume TICKET-00043's shared contracts and assign shared model/caller edits once. This family is independent of
User/Agent credential coverage after its real suppliers; managed-policy references to Role/Permission values do
not by themselves create a blanket wait on TICKET-00045. Identify actual shared-definition/fence supplier edges
at TASK planning rather than impose all-architecture/Common dependencies or reorder the Board.

TICKET-00048 receives a refreshed six-handler and per-batch outcome matrix. Each implementation slice still owns
its behavior/static checks, current documentation, full gate and independent acceptance; final reconciliation is
not deferred testing. Existing Feature/policy acceptance remains historical baseline, visibly amended only by the
approved optional-audit destination, not rewritten as already implemented.

## Acceptance Evidence

- [ ] All six handlers explicitly support optional audit and validated provenance without transport lookup,
  fallback actor identities, new authorization or newly required audit-only input when disabled/no-change.
- [ ] Code-defined provisioning accepts fixed-nil SystemId once per Command; all-existing definitions remain a
  no-change pass, and real originating User/Agent attribution is not replaced by worker identity.
- [ ] Single changes and mixed batch fixtures prove correct typed subjects/relationships, trusted outcome time,
  actual revisions/shared correlation, one record per meaningful changed subject and none for unchanged subjects.
- [ ] Persisted batch/state assertions prove actual transaction participation, rollback on audit failure or
  concurrency loss, and durable evidence despite post-commit publication failure without falsely claiming rollback.
- [ ] Discovery/default-Permission, eligibility, managed/reference/retirement protections and current message round
  trips remain correct; evidence contains no raw plan/entity/message/secret/exception snapshots.
- [ ] Each executable slice has applicable focused/regression-first checks, canonical full gate/exact owned statement
  coverage, current callers/docs, complete local evidence and independent Review/behavioral QA.

## Exclusions

Feature discovery redesign, new provisioning infrastructure, business authorization changes, consumer persistence,
new audit query/reporting API, generic deduplication/replay, outbox/jobs, compatibility/migration/backfill, release
and deployment. No audit guarantee covers consumer repository writes bypassing these package use cases.

## Approved TASK Plan and Coverage

John approved the complete four-TASK plan, now written as kind chore with orders 413–416:

| TASK | Owned outcome | Baseline handler coverage | Direct blocker |
| --- | --- | --- | --- |
| [TASK-00158](../tasks/00158-TASK.md) | Manual Feature creation and guarded retirement | CreateFeature, RemoveFeature (2). | TASK-00122 |
| [TASK-00159](../tasks/00159-TASK.md) | Feature status and Permission-binding changes | SetFeatureStatus, SetFeaturePermission (2). | TASK-00122 |
| [TASK-00160](../tasks/00160-TASK.md) | Code-defined provisioning with explicit provenance and per-created-Feature evidence | ProvisionFeatures (1). | TASK-00122 |
| [TASK-00161](../tasks/00161-TASK.md) | Atomic per-changed-subject managed-policy reconciliation | ReconcileManagedPolicy (1). | TASK-00122 |

All four are ready-for-agent because scope is settled; each waits only on TASK-00122 directly, inheriting TASK-00121.
No mutual dependency is demonstrated and orders are preference, not a serial chain. The allocation accounts for
2 + 2 + 1 + 1 = 6/6 handlers without overlap/omission; the shared read-only planner is a TASK-00161 collaborator, not
another handler or an audit writer. Refresh actual additions/moves/removals at execution. These are planning counts,
not completed source audit, transaction/type/supplier/consumer qualification or independent implementation acceptance.

All six current Commands lack actor input. Add explicit optional direct typed actorId and canonical message/current
schema/caller adaptations within each cohesive slice. Disabled/pure no-change gains no newly required audit-only
input; enabled attribution must precede the first mutation needing evidence, not necessarily discovery. Autonomous
provisioning supplies fixed-nil SystemId once per Command, preserving real User/Agent origins where applicable.
No ambient lookup, label parsing, guessed actors, fallback identities, new account or authorization grant follows.

Each slice adds an optional last nullable audit repository and explicitly inserts evidence in the actual mutation
transaction/connection. Use trusted outcome time through the existing Application Clock contract, reusing actual
reconciliation time and adding necessary Feature-handler clock/caller wiring without inventing stored timestamps.
Preserve actual Feature revisions and complete Role incarnation/revisions, without fabricating Permission revisions
or predecessor/successor state. Share real batch correlation and record changed subjects, not incidental writes.
Current reconciliation changes Roles/Permissions only: its Feature-reference checks do not justify invented Feature
changes/evidence. Preserve existing reconciliation-event behavior, including unchanged plans; no new audit Event.

Creation/provisioning retain OFF/revision 1, atomic name/identity uniqueness, lazy defaults and existing choices.
Status/binding no-ops retain full-state/revision/reference validation. Any existing Permission tier remains eligible
as a Feature binding, not a grant or promotion restriction. Retirement needs complete CURRENT discovery and final
full-state CAS; candidate absence is not current absence. Policy application replans inside its transaction and
retains managed/protected/reserved identity and all actual reference/tier fences; preview remains read-only/advisory.
Audit failure/concurrent loss rolls back all staged state/evidence, never concurrent winners. Unknown commit and
ordinary post-commit/both-publisher failure retain truthful outcomes; no retry duplicate/backfill, intercepted Event,
nested commit, rejected-attempt store, issuance warning exception or generic replay/outbox is introduced.

Bound action-specific evidence/context explicitly; exclude raw messages/plans/entities/secrets/diagnostics and
unnecessary personal data. Coordinate actual typing/planner/schema/architecture owners and shared Feature-created
facts once without duplicating corrections or imposing blanket waits. Native UoW mixed with matching local @var for
known owned callback results remains sufficient: no generic supplier, wrapper/result DTO or redundant result check.
Each slice owns callers/fixtures/current docs, supported minimum/ordinary composition, focused checks, full gate/
exact coverage, complete evidence and independent Review/nonvisual behavioral QA. TICKET-00048 owns final cross-family
conformance/adoption guidance, not deferred slice acceptance. Apply ADR 0011 without aliases/legacy modes/backfill;
obtain applicable complete private criteria before implementation/review without disclosure and select the execution
checkout separately. No consumer/Common repair, production schema operation, policy/grant, release or deployment.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 413 | [TASK-00158](../tasks/00158-TASK.md) | Audit Feature creation and retirement | done |
| 414 | [TASK-00159](../tasks/00159-TASK.md) | Audit Feature setting changes | ready-for-agent |
| 415 | [TASK-00160](../tasks/00160-TASK.md) | Audit code-defined Feature provisioning | ready-for-agent |
| 416 | [TASK-00161](../tasks/00161-TASK.md) | Audit managed-policy reconciliation | ready-for-agent |
## Decisions and Progress

John approved this requirement in the complete six-TICKET split, then the complete four-TASK plan now written as
TASK-00158–00161. Each is ready-for-agent with only TASK-00122 as a direct blocker; none is implemented or independently
accepted. Earlier scope/order/blockers and first Board selection TASK-00107 remain unchanged. Manifest allocation is
planning coverage, not source audit or product/consumer qualification.

Planning only: no source/dependency/Common/consumer mutation, product build, new checkout/branch/worktree,
commit/publication, independent implementation acceptance, release or deployment is claimed.

Planning generation/read-only validation passes (225 records, 99 active), documentation links and diff/new-record
whitespace checks pass, and resolved manifests allocate 6/6 handlers as 2 + 2 + 1 + 1 without overlap/omission.
The read-only planner is separately owned by TASK-00161; metadata verifies orders 413–416 and only TASK-00122 as each
direct blocker. Source/dependency status remains unchanged and the Board still selects TASK-00107 first.
John's subsequent request for five-framework Feature-check adapters remains separate planning, not these audit TASKs.

TICKET-00047's subsequent four-TASK plan is approved/written as TASK-00162–00165, separate from Feature/policy audit.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection.
EPIC-00016's six accepted TASK plans are complete, not implemented/qualified; added EPIC-00015 Agent conversion and
actual Common supplier planning/qualification remain separate unfinished handoffs. Finish selected remaining planning
before Board-based execution selection; checkout choice and implementation stay explicit.
