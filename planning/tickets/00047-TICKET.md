---
id: TICKET-00047
epic: EPIC-00017
title: Audit Agent credential and recovery outcomes
status: ready-for-agent
---

# Audit Agent credential and recovery outcomes

## Problem and Outcome

Agent credential operations already demonstrate atomic audit but use the existing required/narrow evidence
contract; delivery/maintenance outcomes also need consistent coverage. Under
[EPIC-00017](../epics/00017-EPIC.md), apply the new optional contract without weakening retained-key recovery,
current writer fences, external-effect safety or confirmed-result warning semantics. Audit must report truth,
not disguise committed issuance, uncertain commit or a delivery that cannot be undone by database rollback.

## Approved caller-contract amendment

John subsequently chose [TICKET-00053](00053-TICKET.md)/[TASK-00182](../tasks/00182-TASK.md)'s void Commands and independent
outcome Queries, invocable sync or async without a transport assumption. Direct-service/result/warning references
below are baseline contracts until conversion; afterwards this audit ticket follows the actual handlers/Queries,
retaining commit/provenance/no-duplicate-evidence safety without resurrecting removed results or synchronous completion.
Conversion stays separately owned and is not an audit prerequisite. Both-publisher failure still cannot hide committed
issuance; no new durable warning storage or result-capable Common bus is implied.

## Use Cases and Contracts

| Actor and trigger | Existing synchronous/worker interaction | Required outcome |
| --- | --- | --- |
| Authorized caller provisions or rotates Agent credentials | AgentProvisioningService::provision / AgentCredentialRotationService::rotate | Meaningful newly committed issuance gets enabled audit; retained-key resolution creates neither new issuance nor duplicate mutation evidence. |
| Authorized caller revokes credentials | Existing Agent credential lifecycle operation | Required enabled evidence commits with revocation/correlated lifecycle state; existing publication policy remains. |
| Trusted worker delivers or confirms delivery failure | Agent delivery outcomes and existing recovery contracts | Audit meaningful committed delivery/failure transitions, not every poll or temporary claim. |
| Trusted maintenance performs rewrap, expiry or cleanup | Existing Agent delivery/maintenance operations | Evidence for meaningful confirmed outcomes under existing epochs/fences; no invented rollback of external effects. |
| Caller/worker recovers after lost acknowledgement or fault | Existing retained keys, receipts, tombstones and authorized discovery/status | Original operation identity and safe recovery remain authoritative; reads/claims do not automatically become new business mutations. |

These are related synchronous outcomes, not additional handlers beyond the parent's 37-command matrix. Existing
status Queries/discovery remain read-only and authorization-scoped. Preserve existing Events, warning/result shapes
and sensitive non-serializable contracts; no new audit Query, permission grant or command bus result channel is needed.

## Requirements and Validation

- Apply [TICKET-00043](00043-TICKET.md)'s typed evidence and optional last nullable audit repository across owned
  issuance/lifecycle/delivery/maintenance use-case owners, including previously mandatory audit writers. Disabled
  execution intentionally lacks a durability promise; configured storage failure cannot silently disable audit.
- Preserve original admitted User/Agent attribution through worker execution. Use SystemId for genuinely autonomous
  maintenance, never as fallback for missing provenance. Reuse sufficient existing actor inputs; where absent,
  supply explicit optional provenance and validate before the first mutation requiring enabled evidence. Do not
  require newly added audit-only actor input for disabled or pure resolution/no-change execution.
- Insert enabled evidence inside the actual mutation transaction/connection under the same required writer/
  cohort/operation fences. Include typed Agent/operation/delivery relationships, applicable authoritative revisions,
  trusted outcome time and scoped retained-operation identity/correlation, not raw issuance/request serialization.
- Preserve the existing admission, expected-state, cohort generation, restoration readiness, delivery/cleanup
  epochs, cancellation and stale-writer defenses. Optional audit amends only audit requirements, not these safeguards.
  Application handlers/services coordinate; Domain retains its invariants and lifecycle transitions.
- Audit failure before commit aborts required staged changes. After lost commit acknowledgement, preserve the
  existing indeterminate outcome and retained-key resolution; neither assume rollback nor blindly issue again.
- Preserve the narrowly scoped provisioning/rotation confirmed-result plus sanitized publication-warning contract,
  including both publishers failing. Committed state/evidence survive publication failure. Revocation and other
  operations retain their own existing failure propagation; do not extend the warning exception indiscriminately.
- Retained-key resolution returns original issuance metadata without duplicate mutation evidence. A genuinely new
  operation gets its own enabled evidence. Correlation/evidence IDs are not universal replay tokens. Later opt-in
  does not backfill old unaudited issuance or change its retained key just to manufacture an audit record.
- Keep external sink send/cleanup outside transactions, rewrap inside its bounded fenced transaction, and separately
  committed claims/receipts truthful: a failed audit transaction cannot reverse an already performed external effect. Preserve current recovery/fencing and audit meaningful
  confirmed delivery/failure, expiry, rewrap and cleanup outcomes, not internal claims/helper calls. Do not duplicate
  the owning use case's record through lower-level collaborators.
- Exclude generated/raw credentials, keys, tokens, hashes, ciphertext, sink payloads, raw messages, unnecessary
  personal data and diagnostic exceptions. Context/reasons require action-specific safety admission; safe evidence
  is not automatically public API/log data. Rejected attempts have no committed-outcome record here.

## Dependencies and Existing Work

Consume TICKET-00043; family coverage is independent of User/Feature/administration work except actual identified
shared suppliers. Coordinate with TICKET-00036's Agent name/operation ownership and TICKET-00037/00040's typing
without duplicating their redesign or inventing blanket architecture/Common waits. Assign overlapping edits and
complete current callers once in TASK planning, retaining existing Board priorities.

EPIC-00015/00016 separately retain eventual result-preserving provision/rotation conversion to Commands. It is
excluded here and is not a prerequisite for audit in today's synchronous services. No result capture, mutable
metadata, bus shim, handler bypass or forwarding facade follows from this audit requirement.

Obtain applicable approved private operation/recovery briefs before implementation/review via the authorized
channel; public records neither disclose them nor prove their availability. Feed explicit package-versus-consumer
qualification limits to TICKET-00048; each implementation slice owns its own checks/docs and independent acceptance.

## Acceptance Evidence

- [ ] All listed issuance, revocation, delivery and meaningful maintenance outcomes have refreshed coverage and
  explicit enabled/disabled composition/provenance without weakening business-required actor/fence admission.
- [ ] Persisted-state probes prove audit/state atomicity and pre-commit failure rollback; uncertain-commit cases
  preserve both possible actual outcomes and safe original-key resolution without duplicate issuance/evidence.
- [ ] Confirmed provision/rotation metadata and sanitized warnings remain correct even when both publishers fail;
  other operations retain their publication behavior and primary fault without false rollback claims.
- [ ] Real recovery semantics are modeled for external effects/claims/receipts, meaningful failed/expired/confirmed
  transitions, stale writers and no-change resolution; helper polling does not manufacture mutation records.
- [ ] Typed evidence preserves scoped operation identity and applicable complete revisions without raw secret,
  payload or diagnostic capture. Package fixtures do not qualify actual sinks/storage/restoration/activation/use.
- [ ] Each executable slice includes applicable focused/regression-first checks, canonical full gate/exact owned
  statement coverage, current callers/docs, complete local evidence and independent Review/behavioral QA.

## Exclusions

Agent Command conversion or result-bus mechanisms, new business authority, serializable secrets, generic replay/
job/outbox infrastructure, consumer persistence/crypto/sink mutation, actual consumer qualification, durable
rejected-attempt storage, compatibility/backfill/data operations, release and deployment.

## Approved TASK Plan and Coverage

John approved the complete four-TASK plan, now written as kind chore with orders 417–420:

| TASK | Owned outcome | Primary service / outcome-entry coverage | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00162](../tasks/00162-TASK.md) | Recoverable issuance and retained originating provenance | AgentProvisioningService::provision; AgentCredentialRotationService::rotate (2 services, 2 entries). | TASK-00122 |
| [TASK-00163](../tasks/00163-TASK.md) | Credential revocation | AgentCredentialLifecycleService::revoke (1 service, 1 entry). | TASK-00122 |
| [TASK-00164](../tasks/00164-TASK.md) | Delivery outcomes and scheduler recovery | AgentCredentialDeliveryService::deliver; AgentDeliveryRecoveryService::recover (2 services, 2 entries). | TASK-00122, TASK-00162 |
| [TASK-00165](../tasks/00165-TASK.md) | Material rewrap, expiry and cleanup | AgentDeliveryMaintenanceService::rewrap/expire/cleanup (1 service, 3 entries). | TASK-00122 |

All four are ready-for-agent because scope is settled; each consumes TASK-00122 and inherits TASK-00121. Delivery also
waits on TASK-00162's actual retained-origin data, since current operation/issuance values contain no typed origin
for restarted workers. There is no demonstrated mutual wait or issuance dependency for independent revocation/
maintenance. Orders are preference except those explicit supplier edges; no earlier scope/order/blockers change.
Allocation is 2 + 1 + 2 + 1 = six primary service owners and 2 + 1 + 2 + 3 = eight outcome entry points, without
intersection/omission. Recovery delegates evidence insertion rather than duplicate the delivery owner; nextRunAt
stays pure scheduling. Refresh actual relocated/added/removed owners at execution; these are planning counts, not
completed source audit, product execution or native/supplier/consumer qualification.

TASK-00162 owns paired provision/rotation optional wiring and necessary retained-provenance definitions/copies/
hydration/current callers once, consuming TASK-00122's shared typed port/evidence adaptation without redoing it.
Preserve canonical request/key/destination/associated-data identity and safe issuance/result/View shapes, rather
than parse scope labels, guess current worker origin, add public origin fields or create an audit Query. Current
worker authorization remains independent; originating attribution grants nothing. Retained-key resolution and later
audit opt-in do not manufacture duplicate or backfilled issuance evidence, new operations or old transient warnings.

TASK-00164 records meaningful expiry/exhaustion reached inside claimDelivery as well as actual committed delivery/
failure outcomes. Temporary claims/admissions/polls and unchanged recorded-result reconciliation are not mutation
evidence. Keep claim/admission/outcome transactions separate and protected sink effects outside transactions, using
retained originating provenance after restart. Audit failure after a send cannot undo it; receipt-first recovery and
both uncertain outcomes retain original bytes/keys and all current fences without blind restaging/reissuance.

TASK-00165 owns all three maintenance methods/optional wiring together. Rewrap remains inside one bounded fenced
transaction; cleanup has separate admission, external exact-tuple removal/tombstone and acknowledgement transactions.
Evidence commits with the real outcome, including newly acknowledged cleanup, not an attempted external call. Expiry
through rewrap and permanent source failure matter; no-change/already-acknowledged/helper execution creates no duplicate.
Autonomous SystemId is explicit, never fallback for missing User/Agent origin. Preserve immutable retained history,
key/reference/epoch/deadline fences and delayed-replay/cleanup protections without a physical/database atomicity claim.

All actual evidence owners use optional last nullable audit injection; configured failure never means disabled.
Business-required actors/authorization remain required. Preserve exact trusted outcome time/scoped correlation and
applicable authoritative revisions; bounded action-specific safe facts exclude secrets, ciphertext, raw payloads and
diagnostics. Only issuance returns confirmed metadata with sanitized publication warnings; revocation retains its
ordinary throw and worker/maintenance paths their precise safe result containment. No Event-result inspection,
repository/UoW interception, rejected-attempt durability, generic replay/outbox or duplicated lower-helper evidence.

Coordinate actual typing/aggregate/dispatch owners once without blanket waits. Native UoW mixed with matching local
@var for known owned callback results remains sufficient; no generic supplier, wrapper/result DTO or redundant check.
Future Agent Command conversion remains separate, not an audit prerequisite. Each slice retains current callers/docs,
focused checks, supported minimum/ordinary composition, canonical full gate/exact coverage, complete evidence and
independent Review/behavioral QA. TICKET-00048 retains final cross-family conformance/adoption limits, not deferred
slice acceptance. Obtain complete applicable approved private criteria before implementation/review without disclosure;
choose the execution checkout separately. Apply ADR 0011 without legacy modes/backfill or consumer/Common repair.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 417 | [TASK-00162](../tasks/00162-TASK.md) | Audit recoverable Agent issuance | ready-for-agent |
| 418 | [TASK-00163](../tasks/00163-TASK.md) | Audit Agent credential revocation | ready-for-agent |
| 419 | [TASK-00164](../tasks/00164-TASK.md) | Audit Agent delivery outcomes and scheduler recovery | ready-for-agent |
| 420 | [TASK-00165](../tasks/00165-TASK.md) | Audit Agent material maintenance | ready-for-agent |
## Decisions and Progress

John approved this requirement in EPIC-00017's six-TICKET split, then the complete four-TASK plan now written as
TASK-00162–00165. All consume TASK-00122; TASK-00164 additionally waits on TASK-00162 for real retained originating
provenance. Readiness is settled scope, not supplier completion, implementation or independent acceptance. Existing
scope/order/blockers and first Board selection TASK-00107 remain unchanged.

Planning only: no source/dependency/Common/consumer mutation, product build, new checkout/branch/worktree,
commit/publication, independent implementation acceptance, release or deployment is claimed.

Planning generation/read-only validation passes (234 records, 108 active), alongside documentation links and diff/
new-record whitespace checks. Resolved manifests allocate six primary services and eight outcome entry points without
overlap/omission; metadata confirms orders 417–420, TASK-00122 on all four and additional TASK-00162 only on TASK-00164.
This is planning coverage, not source/runtime/supplier/consumer qualification. Source/dependencies and first Board
selection TASK-00107 remain unchanged; no product build was run.

TICKET-00048's subsequent four-TASK plan is approved/written as TASK-00166–00169, completing all six audit TASK plans
as 24 chores, not implementation/qualification. Its final reconciliation consumes this ticket's four actual suppliers;
existing retained-origin, scope/order/blockers and UoW mixed/local @var decisions remain unchanged.

TICKET-00049's subsequent two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's subsequent Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's subsequent portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced two-chore plan is now written, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. Added EPIC-00015 Agent
conversion and actual Common supplier planning/qualification
remain separate unfinished handoffs. EPIC-00016's accepted split is TASK-planned, not implemented/qualified. Finish
selected remaining planning phases before Board-based execution selection; checkout choice remains explicit.
