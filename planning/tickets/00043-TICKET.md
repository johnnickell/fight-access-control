---
id: TICKET-00043
epic: EPIC-00017
title: Define typed audit evidence and explicit attribution
status: done
---

# Define typed audit evidence and explicit attribution

## Problem and Outcome

The existing audit contract cannot faithfully describe all package subjects, originating actors, revisions or
outcome times. Under [EPIC-00017](../epics/00017-EPIC.md), evolve AuditEvidence and AuditEvidenceRepository into one
precise, secret-free evidence contract. Define explicit optionality and attribution rules without adding a generic
orchestration service. Family tickets own insertion and admission within their actual use cases.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Trusted caller supplies provenance for a meaningful outcome | Existing Commands and synchronous inputs; direct typed actorId | Preserve the originating User, Agent, system or anonymous identity without transport lookup or implied permission. |
| Handler records a committed change | AuditEvidenceRepository::add inside the owning transaction | Typed actor, subject, action and applicable complete outcome facts, never arbitrary event/payload inspection. |
| Integrator constructs or hydrates evidence/identities | Current supported value and persistence shapes | Validate kinds, fixed-nil special IDs, required facts and bounded safe context. |
| Integrator omits audit storage | Optional last repository argument on covered owners | Intentional disabled audit, with no durable-evidence promise and no newly required audit-only actor. |

No new audit Query, transport endpoint, permission grant or success Event is required. Existing publication/failure
contracts remain authoritative; evidence is not diagnostic telemetry or a post-commit event projection.

## Requirements and Ownership

- Evolve the existing evidence/repository contracts rather than add parallel audit ports, actor resolvers or
  lifecycle layers. Domain owns evidence invariants; Application owns explicit transaction coordination.
- Use direct UserId, AgentId, SystemId or AnonymousId actor values, not an AuditActor wrapper or service-name
  string. SystemId and AnonymousId follow Common's existing UniqueId pattern with the accepted fixed-nil exception:
  only Uuid::NIL is valid, generate() returns nil, fromString(Uuid::NIL) succeeds, and non-nil construction/hydration
  rejects. Keep their types distinct in equality and persistence; no account row or singleton is needed.
- Evidence has a unique non-nil record identity, typed primary subject and package-defined action, trusted outcome
  occurrence time, applicable typed relationships, authoritative before/after revisions and real message/operation
  correlation. Preserve Role incarnation and scoped operation-key semantics; do not fabricate absent revisions.
- Support demonstrated User, Agent, Role, Permission and Feature subjects, plus session/grant/delivery/operation
  references where actual outcomes require them. No arbitrary objects, unrestricted type/ID strings, raw messages,
  entity snapshots or untyped context bags. Concrete factories/values must remain cohesive and bounded.
- Bound safe context per action. Exclude credentials, tokens, hashes, ciphertext, diagnostic exceptions and
  unnecessary personal data. Free-text reasons require explicit field admission/redaction, not a string type or
  regex assumed to guarantee secrecy. Audit contents are not automatically authorized public/log response data.
- Define optional last nullable AuditEvidenceRepository injection everywhere covered, including formerly mandatory
  writers. Omitted/null intentionally disables audit; configured failure cannot trigger no-op/fallback behavior.
  Mixed per-owner wiring is explicit; no enable flag or no-op repository is required.
- Actorless Commands gain optional typed actorId, reusing sufficient existing actor fields. Enabled auditing
  requires attribution before the first mutation requiring evidence, not necessarily before discovery; pure
  no-change and disabled paths need no new audit-only input. Preserve existing business-required actors and
  current canonical message round trips; absence must not be silently coerced to system/anonymous.
- System identifies deliberate autonomous work; anonymous identifies a deliberately unauthenticated initiator.
  Workers preserve an admitted initiating User/Agent. Actor attribution grants no authority and uses no ambient
  HTTP/Console/MCP/principal lookup. Preserve entry authorization and retained target/scope policy.
- Enabled mutation/evidence use the same transaction/connection and required evidence precedes commit. Audit
  failure aborts staged work; uncertainty, post-commit failure and irreversible external effects retain truthful
  existing outcomes. No-change/rejected/rolled-back attempts get no committed-mutation record; no universal replay
  guarantee follows from evidence/correlation IDs. Later opt-in does not backfill history or change retained keys.

## Dependencies and Sequencing

This supplies shared contracts to [TICKET-00044](00044-TICKET.md) through [TICKET-00047](00047-TICKET.md).
Assign shared model changes and directly necessary caller adaptations once at TASK planning; deliver runnable
current-contract paths rather than broken intermediate interfaces or compatibility shims. Family tickets own new
coverage, actual constructor/message admission and transaction insertion, not duplicate model acceptance.

Coordinate typing with TICKET-00037/00040 and architecture with EPIC-00015 without expanding existing TASKs or
waiting for every redesign. No new Common mechanism is demonstrated as necessary; use actual supported transaction
and Domain ID APIs, recording any genuine qualification gap for affected adoption only. TICKET-00048 starts its
inventory early. This TICKET is a requirement group, not a predetermined layer-only PR.

## Acceptance Evidence

- [ ] Current valid/invalid construction, hydration and serialization preserve typed actors/subjects, unique evidence
  identity, occurrence time, applicable complete revisions, relationships/correlation and bounded safe context.
- [ ] Both special actor IDs enforce fixed nil through every public construction/hydration path, generate returns
  nil, and persistence/equality distinguish their kinds despite identical UUID text. Evidence IDs are not fixed nil.
- [ ] Shared optionality/admission contracts are explicit: configured failure is not disabled audit, missing actor
  is not fabricated provenance, and pure no-change/disabled execution gains no audit-only actor obligation.
- [ ] Necessary current callers/test adapters are updated coherently; no unsafe casts, string bags, hidden service
  or legacy-reader shim bypasses validation or makes unaudited execution appear durable.
- [ ] Each executable slice includes applicable focused/regression-first checks, canonical full gate, exact owned
  statement coverage, current documentation/examples, complete local evidence and independent Review/behavioral QA.

## Exclusions

Generic audit/identity frameworks, actor wrappers, no-op repository requirements, production persistence/schema
operations, rejected-attempt storage, outbox/jobs, generic idempotency, audit query/reporting/retention systems,
Common/consumer mutations, compatibility/backfill, release and deployment. Family behavior is owned by its tickets.

## Approved TASK Plan

John approved the complete two-TASK split. These are executable scope records, not implementation acceptance:

| TASK | Order | Outcome | Actual local blocker |
| --- | --- | --- | --- |
| [TASK-00121](../tasks/00121-TASK.md) | 401 | Introduce fixed system and anonymous identities using the supported Common UniqueId contract. | None demonstrated. |
| [TASK-00122](../tasks/00122-TASK.md) | 402 | Replace the evidence contract across all directly affected existing writer/caller/test paths together. | TASK-00121. |

TASK-00121 is the small independently verifiable prefactor. TASK-00122 owns the cohesive breaking replacement:
the baseline is 21 audit-writing owners (17 CommandHandlers and four synchronous services), not just the two Audit
Domain files. It includes directly necessary typed input/port/caller/fixture adaptations without compatibility
shims, inferred actor labels or broken intermediate interfaces. Refresh that manifest against actual source at
execution; a planning reference count is not full behavioral or transaction qualification.

This owns shared definitions and necessary caller adaptations once. Family TICKET-00044 through TICKET-00047 still
own actual optional constructor wiring, family-specific admission and expanded outcome coverage. TASK-00122 defines/
documents the optionality contract but must not claim all 37 handlers become optional/audited as part of the shared
replacement. TICKET-00048 starts cross-family inventory/conformance foundations early; each slice keeps its own
tests/docs/full gate and independent Review/QA. Existing TASK scope/order/blockers and EPIC-00015/00016 ownership
remain unchanged; source overlap is not a blanket dependency. Choose the implementation checkout separately.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 401 | [TASK-00121](../tasks/00121-TASK.md) | Introduce fixed system and anonymous identities | done |
| 402 | [TASK-00122](../tasks/00122-TASK.md) | Replace audit evidence contracts across existing writers | done |
## Decisions and Progress

Both child TASKs now have independent acceptance and applicable behavioral QA. TASK-00122's accepted typed
replacement covers 22 actual owners / 23 sites; its current checkpoint owns exact candidate, integrated gate and
qualification limits. TICKET-00043 closes with its children. Actual optional family wiring, expanded outcomes and
consumer composition remain with TICKET-00044–00048; EPIC-00017 stays open. Earlier planning checkpoints below
remain historical, not a claim that the shared replacement is still unimplemented.

John approved this requirement in EPIC-00017's six-TICKET split, then the complete two-TASK plan now written as
TASK-00121/TASK-00122. Orders 401/402 follow the existing portfolio; only TASK-00122 is blocked by TASK-00121.
No implementation, completed qualification, source/dependency change, product build, worktree/branch creation,
commit/publication, independent implementation acceptance or release follows from writing these records.

At this two-TASK checkpoint, planning generation/read-only validation passed (186 records, 60 active), plus
documentation links and diff/record whitespace checks. A source-reference inventory verifies TASK-00122 names all 21 current direct audit-writer owners;
this is planning coverage, not a product test or actual shared-connection/consumer qualification. Board selection
remains TASK-00107; no product build was run.

TICKET-00044's subsequent five-TASK plan is now approved/written as TASK-00123 through TASK-00127; its ownership/
acceptance remains in that ticket, not absorbed here.

TICKET-00045's subsequent five-TASK plan is approved/written as TASK-00153–00157, each consuming only TASK-00122
directly for actual family insertion; shared replacement ownership is unchanged.

TICKET-00046's subsequent four-TASK plan is approved/written as TASK-00158–00161, each blocked only by TASK-00122.
TICKET-00047's subsequent four-TASK plan is approved/written as TASK-00162–00165, with shared replacement scope unchanged.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00016's
accepted TASK split is complete, not implemented/qualified; added Agent conversion and real supplier qualification
remain separate. Finish selected remaining planning before Board-based execution selection.
