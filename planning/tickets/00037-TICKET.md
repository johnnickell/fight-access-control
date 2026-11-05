---
id: TICKET-00037
epic: EPIC-00016
title: Make Domain and repository contracts precise
status: ready-for-agent
---

# Make Domain and repository contracts precise

## Problem and Outcome

Native PHP declarations and partial annotations cannot establish the correctness of every Domain value flowing
through the library. Under [EPIC-00016](../epics/00016-EPIC.md), audit and correct all Domain declarations and their
actual callers so aggregates, repositories, messages and results expose precise, behaviorally valid contracts.
This delivers corrected contracts, not only a catalog of possible defects.

## Use Cases and Contracts

| Actor and trigger | Commands / Queries / Events | Outcome and side effects |
| --- | --- | --- |
| Integrator constructs or hydrates a Domain value | Every owned message/value and current persisted shape | Valid identities, enums, collections and required fields have exact types and canonical round trips. |
| Handler reads or persists an aggregate | Existing repository operations | Declared aggregate, nullable lookup, typed collection and atomic expected-state contracts match actual use. |
| Consumer reads a safe result or handles an Event | Existing Views, results and Events | Declared fields/element types and serialized values agree without exposing secret material. |
| Invalid input or authoritative state is supplied | Current Domain validation/rejection | Reject under current invariants without adding Application dependencies or silently coercing invalid state. |

## Requirements and Ownership

- Account for every current Domain declaration in the exhaustive inventory owned by
  [TICKET-00042](00042-TICKET.md), including roots/entities, identities, values, repository interfaces, Commands,
  Queries, Events, results/Views, specifications, exceptions and serialization/hydration collaborators.
- Verify native parameter/property/return types against behavior and refine static collection element types,
  array/list shapes, unions, optional fields and callback contracts. Distinguish legitimate dynamic decoded input
  from an unqualified type that hides a known contract; do not treat every occurrence of mixed as a defect.
- Preserve repository getById aggregate-or-null behavior and specialized CAS/fence/transaction contracts. Audit
  all repository signatures, not just CRUD or the User repository; align owned test adapters and callers.
- Verify that fromArray/toArray and hydration accept only the current supported shape, reject missing required
  fields and preserve declared IDs, timestamps, enums, versions and retained-operation correlation. No coercion,
  historical-reader path or fabricated default may conceal a typing mismatch.
- Keep Domain-owned invariants and exceptions in Domain, depending only on permitted Domain contracts. Never make
  a Domain exception extend Common's Application failure types to obtain an HTTP status.
- Own shared Domain definitions and their round-trip semantics here. [TICKET-00038](00038-TICKET.md) owns upstream
  message-generic adoption; [TICKET-00039](00039-TICKET.md) owns Query result/absence semantics and request checks;
  [TICKET-00040](00040-TICKET.md) owns Application orchestration. Assign overlapping edits/acceptance once during
  TASK decomposition and deliver complete affected caller paths rather than leaving incompatible interfaces.
- Preserve aggregate business rules, authorization facts, secret-free outputs and current-contract restoration/
  concurrency safety. Existing permission grants, Commands and Events are unchanged except justified typing
  corrections required by the parent EPIC; no new business side effects are introduced.

## Dependencies and Sequencing

Begin inventory and Domain analysis without waiting for all Common refactors. Adopt new upstream Domain APIs only
with actual qualified/tagged evidence and sufficient-minimum composition; hold only affected changes. Common owns
universal ResultSet invariants, while this ticket specifies exact repository/value promises and consumes the
supported upstream contract rather than copying it.

Coordinate with [TICKET-00036](00036-TICKET.md) for AgentName/operation-read ownership and
[TICKET-00035](00035-TICKET.md) for User transitions. Do not duplicate those redesigns or freeze old locations.
No blanket dependency on EPIC-00015 is created; TASK planning identifies actual supplier edges and preserves Board
priority. Parent/TICKET approval is not execution or dependency-qualification evidence.

## Acceptance Evidence

- [ ] Every Domain declaration has a correction or justified unchanged disposition, actual affected callers and
  linked evidence in the shared inventory; no aggregate, message or repository family is sampled away.
- [ ] Native and richer static types describe actual Domain values, collections, arrays and nullable repositories;
  owned callers/test repositories compile and preserve their current atomic contracts.
- [ ] Current valid round trips and invalid/missing/type-mismatched input are covered by executable tests, with
  meaningful regression-first evidence for defects and no legacy shape support.
- [ ] Domain remains independent of Application/Adapter; secret safety, current hydration, permission invariants,
  operation identity and concurrency/restoration protections are retained.
- [ ] Each implementation slice has focused behavior/static checks, current docs/examples, canonical full gate,
  exact owned statement coverage, complete local evidence and independent Review/applicable QA.

## Exclusions

New business rules, consumer persistence implementations, schema/data migrations, compatibility layers, Common
implementation, unrelated architectural redesign and release/deployment. HTTP status/rendering is not a Domain
responsibility; presentation and protocol validation belong to TICKET-00041.

## Approved TASK Plan and Coverage

John approved the complete six-slice implementation plan, now written as TASK-00115 through TASK-00120. Each
normally owns one independently reviewable PR and includes necessary caller/test/schema/doc adjustments, not just
isolated Domain annotations. The planning baseline accounts for every one of the 291 current Domain PHP files:

| TASK | Owned Domain boundary | Baseline files | Order | Local blockers |
| --- | --- | --- | --- | --- |
| [TASK-00115](../tasks/00115-TASK.md) | User, RefreshSession, Audit | 67 | 301 | None demonstrated |
| [TASK-00116](../tasks/00116-TASK.md) | ActivationGrant, PasswordResetGrant, EmailChangeGrant, CredentialDelivery | 68 | 302 | None demonstrated |
| [TASK-00117](../tasks/00117-TASK.md) | Permission, Role, ManagedPolicy, Authorization | 51 | 303 | None demonstrated |
| [TASK-00118](../tasks/00118-TASK.md) | Agent identity, principal, repository and administration | 32 | 304 | None demonstrated |
| [TASK-00119](../tasks/00119-TASK.md) | Agent operation, cohort, delivery and maintenance | 43 | 305 | None demonstrated |
| [TASK-00120](../tasks/00120-TASK.md) | Feature | 30 | 306 | None demonstrated |

TASK-00118/00119 contain explicit Agent ownership manifests, including Events/exceptions/Query declarations
outside nested directories. TASK-00119 owns shared cohort definitions and aligns affected AgentRepository callers
in the same correction if needed; shared imports and existing interfaces do not justify an artificial blocker.
Refresh all manifests against implementation candidates, preserving ownership through approved moves/additions.
Counts establish exhaustive planning coverage, not completed source review or proof of correctness.

Orders 301–306 follow existing approved work without renumbering it. Their relative order is preference, not a
serial dependency chain or a blanket wait for TASK-00111–00114/Common future releases. Existing architecture
redesigns retain their owners. Newly required supplier evidence or private brief prerequisites hold affected
implementation actions explicitly; approval does not certify their availability.

Each slice produces its exhaustive inventory fragment immediately rather than waiting for TICKET-00042 tooling.
Every demonstrated owned defect is corrected regression-first; already-correct declarations retain justified
verification without cosmetic churn. Bus generics, required-query absence/failure behavior, general Application
classification and protocol mapping remain with TICKET-00038 through TICKET-00041, except directly necessary
caller adaptations needed to keep these Domain corrections complete. Each slice owns its full gate and independent
Review/applicable QA, without waiting to delegate those obligations to final reconciliation.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 301 | [TASK-00115](../tasks/00115-TASK.md) | Correct User, session and audit type contracts | ready-for-agent |
| 302 | [TASK-00116](../tasks/00116-TASK.md) | Correct human credential and delivery type contracts | in-progress |
| 303 | [TASK-00117](../tasks/00117-TASK.md) | Correct Permission, Role and authority type contracts | ready-for-agent |
| 304 | [TASK-00118](../tasks/00118-TASK.md) | Correct Agent identity and administration type contracts | ready-for-agent |
| 305 | [TASK-00119](../tasks/00119-TASK.md) | Correct Agent operation and recovery type contracts | ready-for-agent |
| 306 | [TASK-00120](../tasks/00120-TASK.md) | Correct Feature type contracts | ready-for-agent |
## Decisions and Progress

TASK-00116 now has a Work implementation checkpoint for the four human grant/delivery Domain families, including
owned callers, complete inventory dispositions, runtime/static regressions and schema alignment. Independent
Review and nonvisual QA remain required; the TASK's canonical Work handoff owns exact verification/content/runtime
evidence. This does not accept the slice, complete this TICKET or change sibling ownership/dependencies.


Grill, To Tickets and this TICKET's complete six-TASK decomposition are approved/written. No source/dependency
change, completed audit, product build, commit/publication or independent implementation acceptance is claimed.
TICKET-00038's subsequent three-TASK plan is now approved/written as TASK-00128–00130, all needs-info for precise
supplier/conversion prerequisites. TICKET-00039's five-TASK plan is now approved/written as TASK-00131–00135;
TICKET-00040's revised ten-TASK plan is now approved/written as TASK-00136–00145, with only Event typing held in
TASK-00136 and no UoW generic prerequisite. TICKET-00041's four-TASK plan is now approved/written as TASK-00146–00149;
TICKET-00042's three-TASK plan is now approved/written as TASK-00150–00152, completing this EPIC's 31-TASK split.
Its foundations are independent; final reconciliation consumes the actual accepted correction slices without
changing this task family's scope/blockers or introducing a UoW supplier.
This does not complete EPIC-00015's conversion or EPIC-00017's remaining audit plans; checkout choice remains separate.

At this six-TASK checkpoint, planning generation/read-only validation passed (177 records, 51 active), along with
documentation links and the diff check. A read-only path-manifest check accounts for 291/291 current Domain PHP files with no overlap or
omission across the six scopes; this is planning coverage only, not source audit or product verification. No product
build was run for these planning/context-only changes.

TICKET-00045's separate administration audit plan is now approved/written as TASK-00153–00157.
TICKET-00046's separate four-TASK Feature/policy audit plan is now approved/written as TASK-00158–00161.
TICKET-00047's four-TASK audit plan is now approved/written as TASK-00162–00165.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169; all six audit plans are complete, not implemented/qualified.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. Added Agent conversion
and actual Common supplier planning/qualification remain separate. Finish selected planning before implementation selection.
