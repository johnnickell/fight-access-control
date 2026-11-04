---
id: TICKET-00053
epic: EPIC-00015
title: Issue Agent credentials through Commands and outcome Queries
status: ready-for-agent
---

# Issue Agent credentials through Commands and outcome Queries

## Problem and Outcome

Move Agent provisioning and credential rotation from direct synchronous service APIs into transport-neutral void
Commands and their Application CommandHandlers. Callers retain the operation key and original request, then obtain
safe authoritative issuance/disposition through Queries rather than a Command result. Commands may be invoked
synchronously or asynchronously, including delayed execution, redelivery and retries; no Console, HTTP, MCP, queue
product, response code or request lifetime is assumed.

John explicitly amended the original result-preserving proposal: use the existing void CommandBus contract and
change the caller contract. A result-capable Common bus, synchronous completion and invocation-specific publication
warnings are no longer requirements. This supersedes conflicting future-conversion prose, not the implemented
service baseline or historical acceptance. Original TICKET-00033–00036 and their eight TASKs retain their scope.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Authorized caller requests provisioning | Secret-free provisioning Command, proposed name ProvisionAgent; existing issuance/failure facts | The executing handler rechecks current scope/destination authority and atomically creates Agent, operation and prepared delivery with applicable audit. The Command returns void, not issuance. |
| Authorized caller requests rotation of an expected predecessor | Secret-free rotation Command, proposed name RotateAgentCredential; existing rotation/failure facts | Execution rechecks scope, original target and destination; expected-state retirement/cancellation and successor operation commit atomically. Delayed execution cannot silently retarget the current credential. |
| Caller or explicitly delegated worker reads the operation | Existing GetAgentOperation / AgentOperationView through the configured QueryBus | Confirmed original issuance and recorded delivery/credential dispositions, or explicit indeterminate status; no raw credential, implicit use authority, write admission or mutation. |
| Command is delayed, duplicated, retried or redelivered after restart | Same original key and request, independently of message/envelope identity | Current authorization and canonical binding still govern; original issuance resolves without duplicate business mutation/evidence/publication. Never generate a replacement key merely because dispatch timed out or status is absent. |
| Execution fails before or around commit | Sanitized Command execution failure; independent status Query | Known pre-commit rejection and commit uncertainty are not an issuance return value. Preserve primary failure and safe same-key recovery; Query absence cannot reconstruct a rejection, prove rollback or identify queue progress. |
| Issuance commits but event publication fails | Existing sanitized failure evidence, best effort | Committed issuance remains queryable even if both publishers fail. No returned publication warning or new durable warning/outbox promise; issuance still proves neither delivery nor activation/use. |

Command names and actual aggregate homes follow the implemented candidate and supported Common contracts. Reuse
GetAgentOperation and its safe view; extend/add a transport-neutral Query only for a demonstrated missing requirement,
not to build a second status model or duplicate existing issuance/disposition data.

## Requirements and Validation

- Move coordination from AgentProvisioningService::provision() and AgentCredentialRotationService::rotate() into
  real registered CommandHandlers with void handling. Domain keeps invariants; handlers own transactions. Do not
  retain unchanged public workflows behind forwarding handlers or expose an alternate direct-handler result path.
- Commands serialize only bounded secret-free intent: original scoped operation key, name or Agent/expected
  predecessor/revision, registered destination binding and applicable explicit provenance. Preserve immutable message
  identity, required-field validation, canonical round trips and metadata without treating message ID as operation ID.
  Generate credentials internally; raw secrets, cipher material and diagnostic exceptions never become messages.
- Retain the key and original request before first dispatch. The same logical operation retains them across retries,
  delayed delivery, process restart and new envelope IDs; distinct operations do not accidentally share a key. Do not
  infer acceptance, execution, commit, rejection or current credential authority from a void dispatch acknowledgement.
- No ambient transport context may be required. Consumers supply the real executing invoker/current explicit delegation
  independently of serialized scope/provenance. Every execution/retry checks current transactional authorization before
  lookup/work and holds scope/target/destination/cohort fences through commit. A payload actor or an earlier enqueue-time
  allow is not authority. Expired/revoked delegation and stale destination/predecessor inputs fail safely at execution.
- Preserve canonical marker/bytes, request comparison, retained original issuance, finite limits, contention handling,
  receipt/tombstone safety, destination ordering, restoration and all-writer fences. Atomic predecessor retirement/
  cancellation and successor insertion remain one business transaction, not eventual consistency between aggregates.
- A confirmed commit completes void handling even if subsequent issuance/failure publication both fail: retain safe
  best-effort failure evidence without throwing an ordinary issuance-rejection merely because publication failed.
  Do not return a warning or promise durable event delivery. Other operations retain their own publication policy.
- Primary pre-commit failures remain sanitized execution failures. Lost commit acknowledgement must surface safe
  execution uncertainty/retryable failure rather than fabricate success or rollback; both possible persisted outcomes
  must reconcile through the original key. Exception propagation is an execution-channel concern, not a portable
  response or a guarantee that an asynchronous submitting caller receives the handler's exception.
- Reuse GetAgentOperation's coherent authoritative projection and authorization before lookup and again before
  disclosure, including absence/terminal records. Confirmed means original issuance exists, not delivery/use authority;
  indeterminate may mean not executed, still in flight, rolled back or unknown. Storage failure is unavailable, not
  absence. Do not fabricate queued/running/rejected states or reconstruct transient publication warnings from events.
  No new durable attempt/rejection journal or broker-status Query is required by this contract.
- Queries stay read-only through Domain repositories, with no generation, commit, audit insertion, publication,
  delivery admission or material access. Safe status may guide a retry of the same Command; a Query never issues or
  repairs work. New-work capacity must not gate authorized existing-operation reads/resolution.
- Preserve the applicable audit contract, original attribution, no-change behavior and actual transaction guarantees.
  TICKET-00047 owns optional audit/retained-origin enhancements; carry the actual accepted candidate contract into the
  handlers without duplicating that redesign or requiring audit work to wait for conversion.
- Apply ADR 0011: update owned callers, registrations, fixtures and composition guidance directly. Remove superseded
  direct service APIs and unused issuance result/warning types after their consumers move to Commands/Queries, with no
  aliases, result shims, mutable messages, event capture or legacy modes. Keep AgentIssuance/current persisted safety.

## Dependencies and Ownership

No result-capable Common supplier is required. Use supported actual void Command/message/handler contracts and
existing Query APIs; verify real registration/routing and sufficient-minimum plus ordinary dependency composition
before adoption. Do not guess a version or stable API from Common planning/local source. A real unavailable consumed
API holds only the affected work, not a hypothetical result-return API that this amendment removes.

Coordinate canonical adoption with TICKET-00034/TASK-00110, name/operation/read ownership with TICKET-00036/
TASK-00113/00114 and audit with TICKET-00047, without expanding earlier scope or adding blockers solely for priority
or source overlap. [TASK-00182](../tasks/00182-TASK.md) owns both conversion paths, necessary Query adaptations, basic
real-bus behavior, current callers/docs, business tests/full gate and independent acceptance as one coherent PR.

[TICKET-00038](00038-TICKET.md)/[TASK-00130](../tasks/00130-TASK.md) qualify exact payload/registration/metadata typing,
void Command handling and Query-result inference on the actual converted production paths. TASK-00130 consumes
TASK-00182 via a real local edge; conversion does not depend on that downstream typing qualification. Common generic
Query/Command typing evidence remains separate from ordinary functional conversion; no Command result type is needed.

Obtain applicable complete approved private operation/recovery criteria through the authorized channel before
implementation/review, without copying restricted material into tracked/public evidence. Consumer persistence,
authority/delegation, broker/worker error handling, crypto/sink and runtime qualification remain consumer-owned.
Transport-neutral package tests do not qualify an actual consumer's queue, storage, activation or deployment.

## Approved TASK Plan and Coverage

John approved one chore TASK and then explicitly replaced its synchronous-result premise with void Commands and
outcome Queries. The same cohesive implementation slice owns provisioning, rotation and their complete caller change:

| TASK | Order | Outcome | Blockers / readiness |
| --- | --- | --- | --- |
| [TASK-00182](../tasks/00182-TASK.md) | 205 | Both void Command paths, authorized outcome Query integration, original-key recovery, owned callers/fixtures/docs and removal of obsolete direct result APIs. | ready-for-agent; no demonstrated local blocker or result-capable-bus hold. |

Order 205 is preference after the original architecture slices, not a dependency or renumbering. Earlier Board
priorities remain intact. This directly selected standalone follow-up has no earlier unfinished Wayfinder phase.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 205 | [TASK-00182](../tasks/00182-TASK.md) | Convert recoverable Agent issuance to Commands and outcome Queries | ready-for-agent |
## Acceptance Evidence

- [ ] Both production handlers own issuance coordination, implement actual void Common dispatch and serialize only
  validated secret-free intent. No direct result/service facade, synchronous-completion dependency or transport assumption.
- [ ] Configured bus execution and real Query dispatch expose authoritative original issuance/dispositions independently
  of submission timing, with authorized read-only indeterminate/unavailable behavior and no invented queued/rejected state.
- [ ] Serialized delay/restart, duplicate/out-of-order delivery and original-key retries retain canonical request identity,
  no duplicate business effects, current execution/read authorization, target/destination/expected-state and writer fences.
- [ ] Pre-commit rollback, both lost-acknowledgement outcomes and both-publisher failure preserve truthful execution failures,
  queryable committed issuance, no diagnostic leakage and no automatic replacement key, delivery or use claim.
- [ ] Applicable audit/provenance/atomic retirement/recovery and existing business scenarios survive conversion; current
  callers/docs demonstrate Commands plus Queries without depending on returned issuance or publication warnings.
- [ ] Supported-minimum/ordinary composition, focused tests, canonical full gate/exact owned coverage, complete local
  receipts and independent Review/behavioral QA qualify conversion, not a transport or consumer deployment.

## Exclusions

Command-result APIs, mandatory sync or async execution, transport endpoints/envelopes/status codes, broker/queue/job
implementation, generic progress/attempt/rejection storage, durable warnings/outbox/exactly-once publication, new grants,
raw-secret messages, revocation/worker conversion, duplicate audit/typing redesign, compatibility/migration/backfill,
Common/consumer mutation, release/version selection, publication and deployment.

## Decisions and Progress

John first approved one requirement TICKET and one implementation chore, then explicitly chose void Commands and a
changed caller contract: add necessary Commands/Queries, assume no particular transport and permit sync or async
invocation at any time. This amendment supersedes the original result-capable-bus requirement and the proposed
needs-info hold based solely on it. TASK-00182 now records the accepted complete one-TASK plan; TASK-00130 has its
real conversion edge and retains only its distinct generic-typing evidence holds. Planning used the selected isolated
worktree; no source/dependency/consumer changes, product build, implementation acceptance, commit/push/PR or release.

Next: `/skill:next` for Board-based execution selection, not automatic implementation.
