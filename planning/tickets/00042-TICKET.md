---
id: TICKET-00042
epic: EPIC-00016
title: Enforce and demonstrate whole-library type correctness
status: ready-for-agent
---

# Enforce and demonstrate whole-library type correctness

## Problem and Outcome

A few repaired handlers or a PHPStan level 6 pass cannot establish correctness throughout the library. Under
[EPIC-00016](../epics/00016-EPIC.md), maintain exhaustive coverage and executable enforcement so every production
boundary is accounted for, findings are corrected and future incompatible use is rejected. Final reconciliation
complements, never replaces, each implementation slice's own verification and independent acceptance.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Maintainer starts the library-wide audit | All production declarations; no new runtime message | Fresh candidate inventory maps every declaration/boundary to an owner and expected contract. |
| Builder corrects or moves code | Existing Commands/Queries/Events and collaborators | Coverage follows additions/moves/removals; each finding links to its correction or justified unchanged contract. |
| Caller supplies incompatible types or expects the wrong result | Existing public/internal callable contracts | Executable static fixtures reject invalid use and preserve valid inference through actual dispatch. |
| Maintainer evaluates completion | Integrated corrected package | All owned findings are resolved, required evidence is current and external qualification limits remain explicit. |

## Requirements and Ownership

- Start the exhaustive inventory and enforcement design early; final reconciliation follows all correction areas.
  The planning baseline is 447 PHP files under src (291 Domain, 154 Application, 2 Adapter), including 37 Command-
  and 22 QueryHandlers at the parent EPIC's stated revision. Re-enumerate actual candidate source and shipped
  OpenAPI metadata rather than certify fixed counts. Record added/moved/removed declarations and new use cases.
- Account for each production declaration/boundary, owner, native/static contract, input/result/absence/failure
  semantics where applicable, actual callers, disposition, finding/correction and executable or justified evidence.
  No sampling, aggregate-only summaries or unreviewed remainder may stand in for complete coverage.
- Assign Domain/repository contracts to TICKET-00037, dispatch relationships to TICKET-00038, query semantics to
  TICKET-00039, remaining non-presentation Application behavior to TICKET-00040 and presentation/schema boundaries
  to TICKET-00041. Shared edits have one owner; completeness requires reconciliation across these boundaries,
  not five independent local passes that lose an end-to-end relationship.
- Inventory behavioral input validation, permission/secret implications, Events/side effects and failure semantics
  where relevant; explicitly justify N/A for pure type-only boundaries. Unavailable private requirements or supplier
  evidence remain precise holds, not guessed behavior or copied restricted criteria.
- Establish static-analysis level/rules sufficient to enforce the agreed contracts over the whole library and
  relevant callers. Justify any intentionally dynamic boundary. Do not claim current level 6 alone proves coverage,
  add ignores/baselines/casts to hide an owned defect, or require an arbitrary numeric level without demonstrating
  the promised contracts. Align fixtures/test-double typing as needed without misrepresenting production guarantees.
- Add executable positive/negative type-contract fixtures demonstrating known collection/array/callback and
  message/result relationships, incorrect use rejection and valid inference. TICKET-00038 owns dispatch-specific
  fixture semantics; consume its evidence rather than duplicate acceptance. Distinguish static inference from
  runtime guarantees; do not require impossible native enforcement of PHPStan generics.
- Integrate required enforcement with the canonical single gate without duplicating it. Keep product behavior/
  contract checks distinct from release-only or optional tooling qualification under the Testing standard. No
  Markdown-inspection test substitutes for behavioral or type-contract evidence.
- Final reconciliation proves no unresolved owned typing finding or unexplained production declaration remains.
  Return newly discovered source corrections to the appropriate requirement owner; do not bury them in a report,
  waive them to meet a count or declare an audit-only milestone to be completed implementation.
- Keep durable acceptance/contract summaries in owning planning/current docs and detailed candidate inventories,
  logs and receipts in purpose-named ignored run evidence under existing project conventions. Do not create a
  parallel delivery queue. Inventory source is bounded by task ownership and must not disclose private diagnostics.

## Dependencies and Sequencing

TASK decomposition must separate early inventory/enforcement foundations from final integrated reconciliation;
this is not necessarily one PR. Early work should not wait for correction completion, while final completion
consumes current evidence from TICKET-00037 through TICKET-00041 and any genuine architecture suppliers.

Unavailable Common contracts/minimum qualification hold only affected adoption, not initial inventory or independent
local repairs. Final acceptance cannot claim an unqualified promised contract passed. Preserve Board order and
establish real TASK edges during decomposition rather than placing cross-repository IDs in local blocked_by.

## Acceptance Evidence

- [ ] A refreshed inventory accounts for every production declaration and all handlers/services/Adapters/shipped
  metadata, with ownership, actual contract, disposition and traceable evidence across additions/moves/removals.
- [ ] Every owned typing finding is corrected and verified or demonstrated not to be a defect; no sample-only,
  audit-report-only, stale-location or unreviewed-remainder completion claim remains.
- [ ] Static enforcement and positive/negative fixtures demonstrate agreed whole-library contracts without new
  suppressions concealing defects, and clearly distinguish native/runtime from generic/static guarantees.
- [ ] Actual minimum/ordinary dependency lanes and real bus/protocol compositions substantiate newly consumed
  Common promises; unavailable supplier or consumer proof is named, not inferred from local source or a tag label.
- [ ] Runtime regressions and integration evidence cover applicable absence, lists, identity/correlation, conflict,
  operational faults and preserved authorization/redaction/atomicity/recovery/post-commit behavior.
- [ ] Each implementation slice retains its own focused checks, canonical full gate/exact coverage, complete local
  receipt, independent Review and applicable QA; final reconciliation uses current identity-compatible evidence
  and does not self-award independent acceptance or waive required checks.
- [ ] Current contract documentation, breakage obligations and exhaustive coverage agree with the integrated
  candidate. Source audit/gate completion neither chooses a release nor qualifies an actual consumer deployment.

## Exclusions

Replacing per-slice tests/Review/QA, consumer/source publication, security-policy changes, Common implementation,
new business functionality, blanket unrelated release gates and claiming completion from counts, a numeric PHPStan
level or an audit report. This requirement introduces no production Command/Query/Event or business side effect.

## Approved TASK Plan

John approved the complete three-TASK plan, now written as kind chore:

| TASK | Order | Delivered outcome | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00150](../tasks/00150-TASK.md) | 299 | Reproducible declaration/boundary inventory, contract ownership and structural drift checking. | None demonstrated. |
| [TASK-00151](../tasks/00151-TASK.md) | 300 | Real positive/negative type-contract fixtures and minimal execution through the canonical gate. | None demonstrated. |
| [TASK-00152](../tasks/00152-TASK.md) | 329 | Sufficient whole-library enforcement and final integrated contract/evidence reconciliation. | TASK-00150/00151; TASK-00115–00120; TASK-00128–00149. |

All three are ready-for-agent because scope is settled. TASK-00152 waits on 30 actual local suppliers: the two
foundations and all 28 correction/adoption tasks under TICKET-00037–00041. Its TASK metadata expands every edge;
these ranges are explanatory, not cross-repository IDs. This is final-completion fan-in only, not a serial chain,
an early supplier wait, or permission to change earlier scope/blockers. Orders 299/300 put foundations before the
existing 301–328 typing corrections without renumbering them; order 329 is final reconciliation. TASK-00107 remains
first on the Board. No blanket architecture/Common/Domain wait is added to independent work.

TASK-00150 covers actual declarations/members/callables and applicable serialization/hydration/metadata boundaries,
not just the 447 src plus eight OpenAPI baseline files. Detect additions/moves/removals and missing/duplicate owners;
keep pending review distinct from corrected/justified unchanged. Compact reproducible configuration belongs in
normal project files, detailed candidate judgments/evidence in TASK-owned ignored paths. Normal checkout checks
must not depend on ignored .runs or a private brief. Structural discovery is not semantic audit completion.

TASK-00151 owns minimal fixture execution and known local identity/list/shape/nullable/callback contracts. Expected
negative diagnostics and actual process results must be checked; extra/missing errors, unavailable inputs/analyzer
or lost positive precision fail. Isolate deliberately invalid fixtures without hiding production defects. Required
product contract fixtures run through bin/quality; runner-specific qualification remains separate tooling, not
default product PHPUnit/coverage. TASK-00128/00129/00130 and TASK-00136 retain actual dispatch/Event fixture semantics
and supplier/runtime qualification; reuse machinery rather than duplicate acceptance or fake missing contracts.

TASK-00152 activates/completes justified whole-library static rules/fixtures and necessary integrated checks, rather
than writing an audit-only milestone. Reconcile every actual contract/caller/disposition and identity-compatible
runtime/protocol/schema/minimum/ordinary evidence. Newly discovered production corrections return to their owning
requirement and normal authorized repair route; final completion waits for accepted integration. No sampled,
unexplained, stale, unresolved or merely count-complete remainder is acceptable. Each earlier slice retains its
own focused checks, full gate/exact coverage, current docs, receipt and independent Review/applicable QA.

TASK-00152 inherits real affected holds through TASK-00128/00129/00130/00132/00136. Actual Common generic/Event/
ResultSet suppliers and EPIC-00015's real conversion still need their own accepted/tagged/minimum proof; local source,
a label, status read or schema fixture cannot establish it. No external supplier TASK/API/version is invented.
Native commitTransactional(): mixed plus matching local @var for known owned callback results remains explicitly
sufficient, including accurate tuple/union branches. No generic UoW API/task, wrapper/result DTO or redundant check
is introduced; that exception does not establish bus/Event inference or validate untrusted values.

Follow actual architecture/API ownership without duplicating relocation/adoption or waiting on every unfinished
native/MCP/audit feature. All three tasks preserve current-contract-only policy and have no new production business
message/mutation/audit/grant or policy effect. Obtain applicable complete authorized private criteria before
implementation/review without disclosure and select the execution checkout separately. Full-gate/independent evidence
is required for each implementation slice; planning approval supplies none of it.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 299 | [TASK-00150](../tasks/00150-TASK.md) | Maintain exhaustive declaration and contract ownership | in-progress |
| 300 | [TASK-00151](../tasks/00151-TASK.md) | Execute type-contract fixtures through the canonical gate | ready-for-agent |
| 329 | [TASK-00152](../tasks/00152-TASK.md) | Enforce and qualify the integrated library contracts | ready-for-agent |
## Decisions and Progress

TASK-00150's Work now implements the early [structural inventory foundation](../../docs/contract-inventory.md)
with independent Review/nonvisual QA still pending. Enumeration and drift enforcement do not complete semantic
review or final reconciliation; TASK-00151 and every correction supplier retain their existing scope/ownership.
The planning-only checkpoints below are historical, not a claim that the current foundation is unimplemented.

John approved this sixth requirement in EPIC-00016, then the complete three-TASK plan now written as
TASK-00150–00152. Both early foundations are independent; final integrated enforcement waits on the 30 named local
suppliers. All six TICKET plans now account for 31 TASKs, not completed source review, enforcement or qualification.

Planning only: no implementation, source/dependency/Common/consumer mutation, product build, new branch/worktree,
commit/publication, release, independent acceptance or deployment is claimed. The Board remains execution authority.

Planning generation/read-only validation passes (216 records, 90 active), documentation links and diff/new-record
whitespace checks pass. Metadata checks confirm exact approved orders, two empty foundation blocker lists, 30 distinct
existing final suppliers and all 31 TASKs across six TICKETs. This is planning structure/coverage only, not completed
declaration audit, enforcement, static/runtime/supplier/consumer qualification or a product build. Source/dependency
status remains unchanged and first Board selection remains TASK-00107.

TICKET-00045's separate administration audit plan is now approved/written as TASK-00153–00157.
TICKET-00046's separate four-TASK Feature/policy audit plan is now approved/written as TASK-00158–00161.
TICKET-00047's four-TASK audit plan is now approved/written as TASK-00162–00165.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection.
EPIC-00015's added Agent conversion still needs its separate requirement/TASK plan, and real Common supplier work/
qualification remains outstanding. Do not recommend Board-based execution until the selected remaining planning
phases are finished; no workflow starts from this approval.
