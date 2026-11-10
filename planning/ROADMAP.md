# Roadmap

## TASK-00182 accepted implementation checkpoint

TASK-00182's integrated void Agent Command conversion has independent Review ACCEPT and nonvisual QA PASS; TICKET-00053 closes with its sole child. TASK-00110's independently accepted Common adoption now completes the remaining TICKET-00034 and EPIC-00015. Publication and final delivery verification were pending at closeout; current results are in `.runs/handoffs/TASK-00182/land.md` and the actual PR. The planning handoffs below retain their historical checkpoints.

## Completed TASK-planning handoff — portable Feature and Permission admission

John approved [EPIC-00018 — Portable Feature and Permission Admission](epics/00018-EPIC.md): separate opt-in Feature
and Permission admission capabilities across Slim, Yii 3, Symfony, Laravel and CodeIgniter 4. Extend FeatureFlag and
introduce AccessControl-owned portable RequiresPermission with repeatable class/method declarations. Class, method,
inherited and trusted route requirements combine conjunctively; deduplicate identical requirements, reject conflicts
and prevent child/override weakening. Attributes cover selected HTTP actions, explicit bindings other supported routes;
no arbitrary PHP-call interception. Align affected discovery/provisioning callers with the effective Feature references.

Reuse fresh Feature evaluation and existing trusted User/Agent/anonymous authority. OFF/unmet PREVIEW gives generic
404; missing/broken definitions and operational faults remain safely classified faults, not disabled Features. Feature
ON never grants action permission. An undeclared action has no Feature gate, not implicit public admission. Existing
Permission/public/authenticated rules remain; either capability can run alone and both must pass when composed.
Thin optional native adapters qualify real order/controller limits, request/subrequest lifetime, safe errors,
optional/minimum dependencies, full gate/exact coverage and independent Review/behavioral QA.

Grill and the complete four-TICKET requirement split are approved/written as
[TICKET-00049](tickets/00049-TICKET.md) declarations/discovery, [TICKET-00050](tickets/00050-TICKET.md) five-framework
Feature admission, [TICKET-00051](tickets/00051-TICKET.md) five-framework portable Permission integration and
[TICKET-00052](tickets/00052-TICKET.md) independent/combined conformance/guidance. Declarations supply the independent
capabilities; conformance foundations begin early, final reconciliation consumes accepted integration, and every
slice keeps its own callers/docs/tests/full gate/exact coverage/independent acceptance. These are requirements, not
four PRs or qualified runtime. TICKET-00049's two-TASK plan is now approved/written as
[TASK-00170](tasks/00170-TASK.md) complete effective Feature declarations/discovery/current callers (order 501, no
blocker) and [TASK-00171](tasks/00171-TASK.md) portable Permission declarations/composition (order 502, dependency on
TASK-00170). Both declaration TASKs are now independently accepted with nonvisual QA and done; TICKET-00049 is
complete. The shared/Slim/Yii Feature slice is now accepted under TASK-00172; remaining native admission/integration
and consumer qualification stay unfinished under their separate tickets.
The first owns the small shared pure selected-action/ancestry output; the second reuses it without a
Feature evaluation/discovery/repository/runtime-activation dependency. Feature admission can consume the first without
waiting for the second. Keep new native class/method/repeated/inherited/route metadata coherent with scoped discovery/
current callers and existing raw PermissionDeclaration validation. No partial Attribute-only contract, scanner,
implicit public fallback, redundant route repetition or native enforcement is introduced. Counts/ownership establish
no source/native/scanner/consumer qualification.

TICKET-00050's four-TASK plan is approved/written as [TASK-00172](tasks/00172-TASK.md) shared inward Feature/context
and PSR-15 (separate Slim/Yii proof), [TASK-00173](tasks/00173-TASK.md) Symfony,
[TASK-00174](tasks/00174-TASK.md) Laravel and [TASK-00175](tasks/00175-TASK.md) CI4. Orders 503/504/504/504 select no
relative native-peer priority. TASK-00172 consumes TASK-00170/completed TASK-00103; Symfony consumes TASK-00172/completed
TASK-00104; Laravel consumes only TASK-00172; CI4 consumes TASK-00172 and TASK-00106's accepted native safe error bridge.
Common already supplies a safe Laravel handler, whereas CI4 response helpers do not establish an exception owner.
No TASK-00105 or portable-Permission wait, duplicate base adapter/bridge or runtime Permission activation follows.
The plan allocates all five frameworks as 2 + 1 + 1 + 1; TASK-00172 now supplies independently accepted shared/Slim/Yii
Feature admission. The other native slices and actual consumer qualification remain unfinished. Each slice retains
real target/status/context/order/construction/lifetime/safe error/minimum/ordinary/isolation proof and its own current
docs/gate/exact coverage/independent acceptance.

TICKET-00051's four-TASK plan is approved/written as [TASK-00176](tasks/00176-TASK.md) PSR-15 portable Permission
integration (separate Slim/Yii journeys), [TASK-00177](tasks/00177-TASK.md) Symfony,
[TASK-00178](tasks/00178-TASK.md) Laravel and [TASK-00179](tasks/00179-TASK.md) CI4. Equal order 505 selects no relative
native-peer priority. Direct suppliers are each TASK-00171 plus respectively completed TASK-00103, completed TASK-00104,
accepted TASK-00105 and accepted TASK-00106. Existing effective composition/pure traversal/inward admission need no new
shared prefactor or native-peer/Feature wait. Laravel genuinely consumes its base Permission middleware, unlike the
Feature-only path; CI4 consumes its actual filter/error bridge. No duplicate base implementation or changed earlier
scope/orders/blockers. All five families are allocated as 2 + 1 + 1 + 1, not implemented/native/supplier/consumer proof.
Retain Attribute-only protection, cumulative requirements, raw validation/mode-conflict rejection, independent selection,
trusted context/single authentication and each path's actual native order/form/lifetime/safe error/docs/minimum/ordinary/
isolation/gate/exact coverage/evidence/independent acceptance.

TICKET-00052's reduced plan is approved/written as [TASK-00180](tasks/00180-TASK.md) existing-evidence/gap assessment
(order 499, no blockers, no new tests) and [TASK-00181](tasks/00181-TASK.md) targeted gap closure/current guidance
(order 506, blocked by TASK-00180 and TASK-00172–00179: nine direct edges, declaration/base dependencies inherited).
John rejected the unwritten six-TASK retesting proposal. Reuse supplier unit/integration coverage and acceptance,
prefer units for shared logic and add an occasional native integration case only for a specific otherwise-unproved
risk. No per-framework retest suites, exhaustive combination grid, conformance framework/runner or test quota; sufficient
existing evidence requires no new tests. Current requirement accounting, candidate compatibility, independent Review/
QA and normal full-gate/exact coverage/evidence-reuse rules remain. All four TICKETs are fully TASK-planned as 12 TASKs
(2 + 4 + 4 + 2: ten features/two chores), not implemented/qualified; unfinished children keep parents open. Existing accepted
PSR-15/Symfony/CI4 and accepted TASK-00105 Laravel Permission scope/order/blockers are preserved; assign actual
native suppliers/shared adaptations once without duplicating existing work. Target version remains unassigned; consumer adoption/release
obligations with other EPICs are not inferred. At that planning checkpoint the first Board selection was TASK-00107;
source/dependencies/Common/consumers remained unchanged.

Next: `/skill:next` for Board-based selection, not automatic execution. This standalone destination has no
earlier Wayfinder phase. All six EPIC-00017 audit TICKETs are now TASK-planned below, not implemented/qualified.
EPIC-00015's Agent conversion and existing-package Common adoption now have independent acceptance and applicable
QA in their owning records. Completing audit planning neither supplied that acceptance nor starts a build/publication/release.

## Completed TASK-planning handoff — explicit optional atomic audit

John approved [EPIC-00017 — Explicit Optional Atomic Audit Recording](epics/00017-EPIC.md), covering all 37 current
CommandHandlers and related synchronous outcomes. Handlers explicitly construct evidence and insert it within the
existing mutation transaction. AuditEvidence/AuditEvidenceRepository evolve; an optional last repository dependency
supports intentional opt-out everywhere, including previously mandatory writers. Configured audit remains atomic
and storage failure cannot silently disable it. This explicitly amends prior mandatory-audit target guidance while
source/released contracts remain unchanged until implementation.

Direct typed actor IDs include distinct fixed-nil SystemId/AnonymousId values; only Uuid::NIL is valid and generate
returns nil. Actorless Commands accept explicit provenance, required before an audited mutation, not a pure no-change
pass. Code-defined Feature provisioning supplies SystemId without a login or per-Feature actor declaration. Preserve
safe typed evidence, real transaction/connection participation, no-change/retained-key behavior and truthful commit/
publication/external-effect outcomes. Consumers qualify their persistence/schema, then Agent OS can remove its
business-aware Role audit decorator. No new audit orchestration, outbox or generic replay framework is introduced.

Grill and the complete six-TICKET requirement split are approved/written as
[TICKET-00043](tickets/00043-TICKET.md) through [TICKET-00048](tickets/00048-TICKET.md): typed evidence/attribution;
User account/credential journeys (20 handlers plus synchronous operations); Role/membership/Agent administration
(11); Feature/managed-policy changes (six); synchronous Agent credential/recovery outcomes; and composition/
conformance/adoption guidance. This accounts for all 37 current handlers, not implemented or verified behavior.
Shared contracts supply the four family tickets; inventory/conformance foundations start early, final reconciliation
consumes the family outputs, and every slice retains its own tests/docs/full gate and independent acceptance.
These requirement groups are not six preselected PRs and must not create broken intermediate contracts or shims.

TICKET-00043's complete two-TASK plan is now approved/written: [TASK-00121](tasks/00121-TASK.md) introduces fixed
system/anonymous identities (order 401, no demonstrated blocker), then [TASK-00122](tasks/00122-TASK.md) replaces the
shared evidence contract across the 21 baseline owners and necessary caller/test paths (order 402).
Both TASKs now have independent acceptance and applicable behavioral QA; TICKET-00043 is complete. The refreshed
replacement covers 22 actual owners / 23 evidence sites. Family optional wiring/admission, expanded outcomes and
real consumer qualification remain unfinished in their owning records; the planning manifest itself is not proof.

TICKET-00044's complete five-TASK plan is approved/written as [TASK-00123](tasks/00123-TASK.md) through
[TASK-00127](tasks/00127-TASK.md): eight account lifecycle handlers, four invitation handlers, three password-reset
handlers, four email-change handlers, and RevokeSession plus all seven synchronous User operations. The final slice
owns synchronous audit wiring once, follows actual narrower capability owners and records committed replay revocation
despite outward denial—not benign refresh conflict or rejected-no-mutation attempts. This is planning coverage of
20/20 handlers and seven synchronous seams, not product/consumer qualification. Orders 403–407 each depend only on
TASK-00122 directly; no mutual blocker is demonstrated and each retains its own tests/docs/full gate/independent acceptance.

TICKET-00045's complete five-TASK plan is approved/written as [TASK-00153](tasks/00153-TASK.md) custom Role lifecycle,
[TASK-00154](tasks/00154-TASK.md) Role Permissions, [TASK-00155](tasks/00155-TASK.md) User Role membership,
[TASK-00156](tasks/00156-TASK.md) Agent names and [TASK-00157](tasks/00157-TASK.md) direct Agent Permissions/coordinator
ownership. Orders 408–412 are ready-for-agent, each blocked only by TASK-00122 without a demonstrated mutual wait.
Allocation covers 11/11 handlers as 3 + 2 + 2 + 1 + 3 plus the coordinator solely in TASK-00157, not source audit or
transaction/consumer qualification. Reuse existing required typed actors and actual shared mutation/evidence
transactions; preserve complete Role/assignment revisions, exact persisted rename time and validated no-change.
Coordinator reconciliation belongs to TASK-00157; no interception, nested commit, invented revision or duplicate
helper record. Each slice retains callers/docs/full gate/exact coverage/independent acceptance. No generic UoW
supplier is required; native mixed and matching known-local-callback @var remain sufficient.

TICKET-00046's complete four-TASK plan is approved/written as [TASK-00158](tasks/00158-TASK.md) Feature creation/
retirement, [TASK-00159](tasks/00159-TASK.md) settings, [TASK-00160](tasks/00160-TASK.md) code-defined provisioning and
[TASK-00161](tasks/00161-TASK.md) policy reconciliation. Orders 413–416 each consume only TASK-00122 directly, without
mutual waits. Allocation is 2 + 2 + 1 + 1 = 6/6 handlers, plus the read-only planner as a TASK-00161 collaborator, not
a handler/audit writer. Counts are planning coverage, not qualification. Each slice owns optional typed provenance,
last nullable audit injection, actual same-transaction evidence/time/revisions/correlation and callers/schemas/docs/
gate/exact coverage/independent acceptance. Provisioning supplies SystemId once per autonomous Command; no-change
requires no audit-only actor. Policy reconciliation changes Roles/Permissions only; Feature references are guards,
not invented Feature mutations. Preserve existing event, batch, reference/tier and publication semantics.

TICKET-00047's complete four-TASK plan is approved/written as [TASK-00162](tasks/00162-TASK.md) paired issuance/
retained origin, [TASK-00163](tasks/00163-TASK.md) revocation, [TASK-00164](tasks/00164-TASK.md) delivery/scheduler
recovery and [TASK-00165](tasks/00165-TASK.md) material maintenance. Orders 417–420 each directly consume TASK-00122;
TASK-00164 also waits on TASK-00162's real retained-origin output. No mutual barrier or issuance wait on independent
revocation/maintenance follows. Six service owners/eight outcome entries are allocated, not added CommandHandlers or
source/product/consumer qualification; nextRunAt stays pure scheduling. Preserve canonical/public issuance identity,
independent worker authorization, meaningful claim-path expiry/exhaustion, receipt-first restart, actual mutation/
evidence transactions and external-effect truth. Rewrap is inside one bounded fenced transaction; cleanup external
removal is between admission/acknowledgement. Each slice owns current callers/docs/gate/exact coverage/acceptance.

TICKET-00048's complete four-TASK plan is approved/written as [TASK-00166](tasks/00166-TASK.md) independent inventory/
design, [TASK-00167](tasks/00167-TASK.md) reusable audit transaction conformance,
[TASK-00168](tasks/00168-TASK.md) ordinary Role composition/adoption and [TASK-00169](tasks/00169-TASK.md) final integrated
qualification/current guidance. Orders 399/400 favor early foundations, 421/422 composition/final work. Inventory has
no blocker; conformance consumes TASK-00122; Role composition consumes TASK-00167 and TASK-00153; final reconciliation
has 21 direct output/evidence suppliers: all three plus the 18 family tasks (00123–00127 and 00153–00165). All six audit
TICKETs are now TASK-planned as 24 chores, not implemented/qualified. Inventory covers 37 + 7 + 8 entry points, not
52 writers or source/runtime/consumer qualification. Reopened modeled state/negative controls must prove more than
active flags; actual consumer mapping/connections/locks/process/sinks/restoration and workaround removal stay separate.
Every slice retains its own checks/docs/gate/independent acceptance, without a late-testing or blanket supplier wait.

TICKET-00049's subsequent plan is written above as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's subsequent four-TASK Feature plan is also written above, not implemented/native qualification.
TICKET-00051's subsequent four-TASK portable Permission plan is also written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced two-chore evidence/gap-closure plan is now written, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. This standalone destination has no earlier unfinished Wayfinder
phase. EPIC-00015's separate Agent command-conversion follow-up stays unfinished; EPIC-00016's six accepted TASK
plans are now complete below, not implemented. Existing TASK order/scope/blockers and first Board selection are
preserved; the two new early foundations do not become correction prerequisites. Choose the
implementation checkout separately. No source/dependency/sibling change, execution, publication, version selection
or release is authorized by this planning.

## Current planning handoff — library-wide typing and failure contracts

TASK-00120's existing branch integrates pinned develop `4ea9fcd3a78624fae041232859eae2f249daaf3b`,
preserving the accepted Feature and Agent identity/operation slices. All six Domain children are terminal
here; automatic parent completion closes TICKET-00037 while EPIC-00016 remains open for other unfinished
children. Canonical `.runs/handoffs/TASK-00120/work.md` owns mechanical provenance, fresh integrated
verification and current runtime; Land independently qualifies the bridge before updating PR #167.
Original independent reports and the checkpoint-specific sibling counts below remain historical evidence,
not a new combined verdict or publication/merge claim. The Board owns unrelated execution selection.

### Historical TASK-00120 accepted checkpoint

[TASK-00120](tasks/00120-TASK.md)'s complete Feature Domain slice has independent Review v3 ACCEPT and nonvisual
QA PASS at candidate `fb01e06e9b86ff1bdc550eaef437573ec3896b84` against develop
`458a35ac224d8dd6b855c4652db9fad34c327187`, with no findings; QA passes eight scenarios / 243 fresh assertions.
The TASK is done, with qualified earlier complete-gate/exact-coverage proof. TICKET-00037/EPIC-00016 stay open
for unfinished children, including TASK-00118/00119 here. Publication/final-head checks are pending at this
administrative checkpoint; current exact PR, bridge, evidence and resource results belong to canonical
`.runs/handoffs/TASK-00120/land.md` and the actual PR. No whole-library or consumer qualification is claimed;
independent reports/history remain unchanged and the Board owns execution selection.

### Historical TASK-00119 combined-slice checkpoint

TASK-00118 and TASK-00119 are historically accepted/done; TASK-00120 and other unfinished children keep their
parents open. TASK-00119's requested develop integration preserves both slices and exact historical verdicts.
Independent Review ACCEPT covers integrated candidate `9e5a21be9244c43c2cc8ae36f9a1bc669918f874` against
`309b62f3a76d9a2b399ae5dfc1b3ceb17b4d8780`, with qualified reuse of both original QA scenario evidence sets,
not a fresh integrated QA PASS. The new integration gate passed 4,579 tests / 53,165 assertions and exact
8,193/8,193 statements. Canonical `.runs/handoffs/TASK-00119/work.md` owns integration provenance;
publication/final checks remain pending at this checkpoint, with current administrative bridge and delivery/
resource results in `.runs/handoffs/TASK-00119/land.md` and [PR #169](https://github.com/johnnickell/fight-access-control/pull/169).
The Board owns unrelated execution selection. The exact-subject acceptance checkpoints below remain historical.

### TASK-00119 accepted checkpoint

[TASK-00119](tasks/00119-TASK.md)'s complete operation/recovery Domain slice has independent Review v3 ACCEPT
and nonvisual QA PASS at `3ce0b96c5ca713673be4e74e20f4aa9d46b5c7a4` against
`458a35ac224d8dd6b855c4652db9fad34c327187`; R-001/R-002 are independently resolved. The TASK is done,
not whole-library or consumer qualification. TASK-00118/00120 and other unfinished children keep TICKET-00037
and EPIC-00016 open. Publication/final delivery verification are pending at this administrative checkpoint;
current bridge, checks and delivery/resource results belong to canonical `.runs/handoffs/TASK-00119/land.md`
and the actual PR. Independent reports and historical checkpoints remain intact; the Board owns execution selection.

[TASK-00118](tasks/00118-TASK.md)'s complete Agent identity/administration Domain slice has independent
Review v3 ACCEPT and nonvisual QA PASS at `b4300b8108ff4cffa9499e1f4530d58459d78f6d`; R-01 is resolved.
The TASK is done, with qualified full-gate/exact coverage and nine passing QA scenarios. Publication/final-head
verification are pending at this closeout checkpoint; current delivery/resource outcomes and administrative-only
provenance belong to canonical `.runs/handoffs/TASK-00118/land.md` and the actual PR. TASK-00119/00120 and other
unfinished children keep TICKET-00037/EPIC-00016 open. No whole-library or consumer qualification is implied;
current execution selection remains with the Board, not these historical planning checkpoints.

### Historical TASK-00117 integrated acceptance checkpoint

TASK-00117's integrated QA-01 repair has independent Review ACCEPT and nonvisual QA PASS at
`52540b7293a0cfd84d8fa03230c6e2ab2cde6520` against `e13995a5b2760d4d02300de2b81797b1d009eb38`.
QA-01 / Review I-01 is resolved. Land mechanically integrates develop's runtime-free fixture tooling at
`f48e5dc9966c083e0207430954c54f08d9a97cb0` without changing accepted product/test/fixture contracts.
Exact bridge, new-input gate and current delivery/resource results belong to canonical
`.runs/handoffs/TASK-00117/land.md` and PR #165; publication/final checks are pending at this checkpoint.
TASK-00117 stays done, unfinished children keep its parents open, and the Board owns execution selection.
No human merge, whole-library or consumer qualification is claimed. Original checkpoints/reports remain historical.

### Historical original TASK-00117 acceptance checkpoint

[TASK-00117](tasks/00117-TASK.md)'s complete Permission/Role/ManagedPolicy/Authorization Domain correction slice
has independent Review ACCEPT and nonvisual QA PASS at candidate `191b972258acfa4ca0bb7e8c90ded115a26f2fcc`.
The TASK is done; TICKET-00037 and EPIC-00016 remain open for unfinished children. Publication/final delivery
verification are pending at this closeout checkpoint; current results belong to canonical
`.runs/handoffs/TASK-00117/land.md` and the actual PR. No whole-library or consumer qualification is implied;
execution selection remains with the Board, not the historical planning checkpoints below.

### Historical TASK-00116 integration checkpoint

TASK-00116's published branch now integrates develop's accepted TASK-00115 contracts without changing either
slice's production implementation. Original acceptance checkpoints below retain their exact subjects; the new-base
interaction checks and evidence bridge belong to canonical `.runs/handoffs/TASK-00116/work.md`. Land must qualify
that bridge before updating PR #164. TICKET-00037/EPIC-00016 remain open for unfinished children; no new combined
acceptance, publication, merge or consumer qualification is asserted.

### Historical slice acceptance checkpoints

[TASK-00116](tasks/00116-TASK.md)'s 68-file human grant/delivery Domain slice has independent Review ACCEPT and
nonvisual QA PASS at candidate `080afc43b2b3942625e264547b75cb9b3a74b3bc`, with no findings. The TASK is done;
TICKET-00037 and EPIC-00016 remain open for unfinished children. [PR #164](https://github.com/johnnickell/fight-access-control/pull/164)
is open with accepted implementation; administrative closeout publication/final-head verification remain pending
at this checkpoint. Current delivery/resource results and the administrative bridge belong to canonical
`.runs/handoffs/TASK-00116/land.md`. No whole-library/consumer qualification, approval, merge or release is implied.
The Board owns current execution selection; the planning checkpoints below remain historical.

TASK-00115's complete User/RefreshSession/Audit slice is now independently accepted and done at repaired
integrated candidate `e0853b518997713493fb1473d2ea0f6878479e68`, with Review v3 ACCEPT, nonvisual QA PASS
and complete gate/exact coverage. Publication/final delivery verification are pending at this closeout checkpoint;
current results belong to canonical `.runs/handoffs/TASK-00115/land.md` and the actual PR. TICKET-00037/EPIC-00016
remain open for other unfinished Domain/semantic/enforcement children; consumer qualification remains separate.

Both early foundations, TASK-00150 inventory and TASK-00151 fixtures, are now independently accepted at the
historical candidates recorded below. EPIC-00016/TICKET-00042 remain open for semantic corrections and
TASK-00152's integrated enforcement/reconciliation. Current delivery results belong to each TASK's canonical
Land receipt and actual PR; the Board owns execution selection. No whole-library audit is complete.

### Historical foundation and planning checkpoints

TASK-00151's bounded fixture foundation now has independent Review ACCEPT and nonvisual QA PASS at
`c7b6900ecf3ee376ba152d84eddac0170f1b335d`. Publication/final delivery verification are pending at this closeout
checkpoint; current delivery/resource outcomes are in canonical `.runs/handoffs/TASK-00151/land.md` and the actual
PR. EPIC-00016/TICKET-00042 remain open for unfinished inventory/correction/integrated-enforcement work. The
approved-planning checkpoint below is historical, not a claim that TASK-00151 remains unimplemented or needs QA;
current execution selection belongs to the Board. No whole-library or supplier qualification is implied.

John approved [EPIC-00016 — Library-wide Typing and Handler Failure Contracts](epics/00016-EPIC.md).
The destination is exhaustive audit and correction across all production layers, every command/query handler,
synchronous services and their supporting contracts, with end-to-end typing, explicit absence/failure semantics
and enforceable verification. This is not a sampled lookup audit or an audit-report-only destination.

Common owns missing upstream bus/result guarantees; qualified/tagged APIs and sufficient-minimum composition
remain affected-adoption prerequisites. Coordinate canonical adoption and architecture boundaries with EPIC-00015
without duplicating TASK-00110 or reordering existing TASKs. No version/release, source/dependency mutation,
Common/consumer change or implementation workflow is authorized by this planning approval.

Grill and the complete six-TICKET requirement split are approved/written as
[TICKET-00037](tickets/00037-TICKET.md) through [TICKET-00042](tickets/00042-TICKET.md): Domain/repository contracts,
typed dispatch, exact query results/absence, commands/synchronous Application contracts, presentation/integration,
and exhaustive inventory/enforcement/final reconciliation. Inventory/enforcement foundations start early; each
correction slice retains its own tests, gate and independent acceptance. These are requirement areas, not six PRs.

TICKET-00037's complete six-TASK plan is now approved/written as
[TASK-00115](tasks/00115-TASK.md) through [TASK-00120](tasks/00120-TASK.md): User/session/audit, human credentials/
delivery, Permission/Role/authority, Agent identity/admin, Agent operation/recovery and Feature. The exhaustive
planning manifests account for 291 current Domain PHP files, not completed review or correctness. Orders 301–306
follow existing approved work with no demonstrated local/mutual blockers; each slice starts an inventory fragment
without waiting for later tooling. Shared cohort definitions have one owner, with complete affected callers.

John explicitly selected TICKET-00038 and approved its complete three-TASK plan, now written as
[TASK-00128](tasks/00128-TASK.md) (22 Query dispatch paths), [TASK-00129](tasks/00129-TASK.md) (37 existing void Command
paths) and [TASK-00130](tasks/00130-TASK.md) (amended void Agent Command and outcome Query typing, not conversion).
Orders 307–309 follow existing Domain plans. The original generic hold on TASK-00129 was superseded by John's
single-declaration/local-annotation and trusted matching-map decision; its current disposition belongs to the
owning TASK. TASK-00128/00130 retain their distinct qualified/tagged generic API/minimum evidence requirements.
TASK-00130 now has the genuine TASK-00182 conversion edge, with no reverse dependency. No result-capable bus is
required and ordinary functional conversion does not wait on downstream generic typing. Existing void handlers and
independent Query adoption retain their owners. These
manifests are planning coverage, not executed inference/routing/consumer proof. Coordinate canonical adoption with
TASK-00110 once; semantic owners retain their corrections and every slice retains its own tests/gate/acceptance.

TICKET-00039's complete five-TASK plan is approved/written as [TASK-00131](tasks/00131-TASK.md) through
[TASK-00135](tasks/00135-TASK.md): six required-resource queries, six paged queries, four human credential status/
discovery queries, four Agent operation/scheduler/count queries and two preparation/policy-preview queries. All 22
current handlers have planning ownership without overlap/omission, not executed behavioral/supplier/consumer proof.
Orders 310–314 follow existing typing work with empty local blocked_by and no demonstrated mutual dependency. At
that planning checkpoint four slices were ready-for-agent; current status belongs to the owning TASKs and Board.
At the release-readiness checkpoint, released Common v1.5.0 supplied TASK-00193's `ResultSet::strict()` and
cleared TASK-00132's former external planning-gap hold without a new Common plan/release or local blocker edge.
The owning TASK retains strict-path adoption, sufficient Common minimum, mutable-list handling and actual
minimum/ordinary verification evidence; current status belongs to the owning records, not this historical planning handoff.

TICKET-00040's revised complete ten-TASK plan is approved/written as [TASK-00136](tasks/00136-TASK.md) through
[TASK-00145](tasks/00145-TASK.md): Event-driven delivery/Clock, User lifecycle, human credential workers, synchronous
User/session, Role/membership, Agent administration, Feature/policy, Agent issuance/results, Agent workers and
inward authority/continuation. Primary manifests allocate 119 remaining Application files and 37/37 CommandHandlers
without overlap/omission; together with 22 QueryHandler and 13 Application presentation files, all 154 baseline
Application files have planning ownership. This is not completed source review or type/runtime/consumer qualification.
Orders 315–324 have empty local blocked_by with no demonstrated mutual dependency. At that planning checkpoint
nine slices were ready-for-agent; current status belongs to the owning TASKs and Board. TASK-00136 remains needs-info
for qualified/tagged Event payload/registration APIs and minimum/ordinary proof only.

John removed the UoW supplier hold: native commitTransactional() remains mixed and local @var is sufficient for a
known owned callback return. No generic UoW API/task, replacement wrapper/result DTO or redundant check is required.
This is not a substitute for external validation or bus/Event inference. Shared edits are assigned once and each
slice retains its own callers/docs/tests/full gate/exact coverage/independent acceptance. No Common file is edited.

TICKET-00041's complete four-TASK plan is approved/written as [TASK-00146](tasks/00146-TASK.md) ordinary HTTP,
[TASK-00147](tasks/00147-TASK.md) profile Tools, [TASK-00148](tasks/00148-TASK.md) Resource/Skill and
[TASK-00149](tasks/00149-TASK.md) public payload/declarative metadata. At that planning checkpoint orders 325–328
were ready-for-agent; the first/third had no demonstrated local blockers. TASK-00147 waits on
TASK-00128/00131/00141 and TASK-00149 consumes
TASK-00133's now-accepted status representation. Current executability derives from the owning records and Board.
Primary manifests allocate 23 current files (2 Adapter + 13 Application presentation + 8 OpenAPI) without overlap/
omission, not completed source review or runtime/type/supplier/consumer qualification. Each slice retains actual
protocol/schema composition, minimum/ordinary proof, callers/docs/full gate/exact coverage/independent acceptance.
Known local callback annotations with UoW mixed remain sufficient; schema tests neither qualify ResultSet runtime
invariants nor add a blanket metadata blocker for unchanged wire shapes. No Common/consumer source is edited.

TASK-00150's structural inventory foundation has independent Review ACCEPT and nonvisual QA PASS at
`73154cf29907711e67e254630465dcb16480da66`. Only this tooling foundation is complete; semantic judgments,
TASK-00151/00152 and the unfinished correction suppliers keep TICKET-00042/EPIC-00016 open. Publication/final
delivery verification are pending at this checkpoint; current results belong to `.runs/handoffs/TASK-00150/land.md`
and the actual PR. The planning allocation below records its earlier checkpoint, not current TASK readiness.

TICKET-00042's complete three-TASK plan is approved/written as [TASK-00150](tasks/00150-TASK.md) declaration/
contract ownership (order 299), [TASK-00151](tasks/00151-TASK.md) executable type-contract fixtures (300) and
[TASK-00152](tasks/00152-TASK.md) integrated enforcement/qualification (329). All are ready-for-agent; the independent
foundations have no demonstrated blockers, final work waits on both plus all 28 correction/adoption tasks under
TICKET-00037–00041. These 30 edges are final-completion fan-in only, inheriting real affected supplier/conversion
holds without a blanket early wait. Approved early priorities precede existing 301–328 typing work without
renumbering earlier records, adding dependencies to them or changing the then-current Board selection of TASK-00107.
Inventory is declaration/boundary-aware, not count-only; positive/negative fixture execution is required in the one
canonical gate, distinct from runner tooling qualification. Final work completes sufficient rules/integrated checks
and current contract/evidence reconciliation, returning new production defects to their owners. No audit-only,
unresolved or unqualified promised-contract completion is allowed; every slice keeps its own gate/acceptance.
Known local callback @var with UoW mixed remains sufficient, without a generic supplier or redundant check.

All six TICKET plans are now approved/written (31 TASKs), not implemented/audited/qualified. This standalone
destination has no unfinished Wayfinder phase. TICKET-00045's five-TASK plan is now approved/written above.
All six audit TICKETs are now TASK-planned above as 24 chores, not implemented/qualified.
TICKET-00049's declaration plan is now approved/written above, not implemented/qualified.
TICKET-00050's four-TASK Feature admission plan is now written above, not implemented/qualified.
TICKET-00051's subsequent portable Permission plan is written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced two-chore plan is now written, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection.
John's subsequent five-framework Feature/portable Permission admission interview is approved/written as EPIC-00018
above, with its four-TICKET split and all TASK plans approved/written (12 TASKs), not implementation/qualification. Current
FeatureAvailability still evaluates and FeatureFlag only declares
references; no Feature middleware exists. The new requirements remain separate from audit/existing permission TASKs;
Grill approval is neither implementation nor release/adoption proof. EPIC-00015's added Agent conversion is now fully
planned as [TICKET-00053](tickets/00053-TICKET.md)/[TASK-00182](tasks/00182-TASK.md), with void Commands/outcome Queries
rather than a result-capable Common supplier requirement. Actual canonical/generic supplier qualification remains
separate. Use the current Board for execution selection. Implementation stays separately invoked with an explicit checkout choice;
no source/dependency/consumer change, product build, new branch/worktree, commit/publication or release is claimed.

## First priority — pre-v1 architecture enablers

TASK-00107's guidance candidate has independent Review ACCEPT and QA PASS; publication and final delivery
verification were pending at closeout. The current result is in `.runs/handoffs/TASK-00107/land.md` and the actual PR.
The Board owns current execution routing; remaining implementation is not implied by guidance acceptance.

John approved [EPIC-00015 — Pre-v1 Architecture and Supported Common Integration](epics/00015-EPIC.md), its
four TICKETs ([00033](tickets/00033-TICKET.md)–[00036](tickets/00036-TICKET.md)) and all eight implementation TASKs.
The [Board](tasks/BOARD.md) derives execution priority and blockers from those records; this section is strategy,
not a separate queue or authority to execute.

| Priority | Accepted work | Purpose and dependency |
| --- | --- | --- |
| 10 | [TASK-00107](tasks/00107-TASK.md) — Architecture and naming rules | Independently accepted guidance; reconciles old layout/single-service guidance with the approved target. |
| 11 | [TASK-00108](tasks/00108-TASK.md) — MCP presentation/bindings in Adapter | Independently accepted; provides the canonical boundary before extending MCP features. |
| 12 | [TASK-00109](tasks/00109-TASK.md) — Application capabilities and descriptive names | Independently accepted; combines cohesive inward capabilities and descriptive collaborators with 108's outward MCP boundary. |
| 13 | [TASK-00110](tasks/00110-TASK.md) — Supported canonical Common contracts | needs-info for actual affected tagged/accepted APIs and sufficient minimum; independent ready work proceeds. |
| 201–202 | [TASK-00111](tasks/00111-TASK.md) → [TASK-00112](tasks/00112-TASK.md) — User revisions and synchronous capabilities | Direct synchronous owners now consume the complete User transitions; original independent acceptance remains recorded in each TASK. |
| 203–204 | [TASK-00113](tasks/00113-TASK.md) / [TASK-00114](tasks/00114-TASK.md) — AgentName and operation/read boundaries | Independently accepted, including TASK-00114's integrated authentication-base candidate. Its canonical Land handoff and PR #158 own current delivery results. |

Unfinished MCP roots TASK-00084/00087/00091/00097 now consume 108/110; existing descendants inherit those technical
suppliers. Their new Common family/operation tagged-contract holds remain separate. Laravel TASK-00105 and
CodeIgniter TASK-00106 keep order 104 and their existing prerequisites: no MCP, User-decomposition or Agent-operation
blocker is added. The broader redesign is not a blanket prerequisite or release gate for unrelated capabilities.

Common's inspected local `9e46565633a2ee44fefacf27fc97b6a711467190` has concurrent changes and approved refactors
through TASK-00192: canonical MCP foundation names, separate repository/UoW/read contracts, handler-owned use cases,
typed identities/values and Tool adapters. Those plans are orientation, not actual released/qualified API evidence.
TASK-00110 adopts only affected consumed canonical APIs when genuinely available; individual features qualify their
additional APIs and minima. No guessed version, stable claim from a dev snapshot, AccessControl compatibility alias,
Common/consumer mutation or dependency adoption is authorized through these planning edits.

### Completed planning handoff — void Agent Commands and outcome Queries

EPIC-00015's original four-TICKET/eight-TASK plan is accepted/written; TASK-00107's guidance is independently accepted.
John approved the added [TICKET-00053 — Issue Agent credentials through Commands and outcome Queries](tickets/00053-TICKET.md)
and one chore [TASK-00182](tasks/00182-TASK.md), then explicitly replaced synchronous result-preserving dispatch with
void Commands and a changed caller contract. Commands may be invoked sync or async at any time; no transport assumed.

TASK-00182 (order 205) owns both complete issuance Command paths, authorized outcome Query integration, current callers/
fixtures/docs and removal of superseded direct APIs. Reuse GetAgentOperation/AgentOperationView rather than duplicate
status data. Retain original keys/requests, execution-time authority, atomicity, recovery and publication/delivery
separation. Dispatch acceptance and status absence do not prove issuance or rollback. No returned warning or queue-
progress promise; safe execution faults and authoritative reads carry the amended contract.

No result-capable Common API is required: TASK-00182 is ready-for-agent, with no demonstrated local blocker.
[EPIC-00016](epics/00016-EPIC.md#agent-command-result-coordination-follow-up)/TASK-00130 now qualify exact void Command
and Query typing, not Command results, with a genuine TASK-00182 blocker and distinct generic API evidence holds.
Conversion retains its own business/bus proof and gate; no reverse edge, earlier-task expansion or audit prerequisite.
The follow-up is fully TASK-planned. Next: `/skill:next` for current Board selection; no execution starts automatically.
Planning branch develop integration fast-forwarded to 3efa74d4092d98c3847f57f26896d1912b47256e with user authority,
retaining newer accepted guidance. No source/tests/dependency/Common/consumer mutation, product build, commit/push/PR,
supplier qualification or release follows from this planning update.

EPIC-00014 keeps one combined future release/all five frameworks, opt-in capabilities, independent polling and an unassigned version. Existing acceptance/publication
checkpoints below remain historical; EPIC-00015's current amendments govern conflicting future placement/supplier prose.
Next: `/skill:next` for Board-based selection; no implementation or publication starts automatically.

## Future MCP permission integration — current acceptance

TASK-00085 and TASK-00086 are independently accepted with required nonvisual QA; TICKET-00026 is done.
Qualified TASK-00088 intake verified TASK-00086 PR #141 merged/integrated in `develop` at `7fc6c313c39df96f7e261144025433d2b5c46e31`;
its delivery marker is retired. Historical acceptance/Work checkpoints remain intact.
TASK-00088 and TICKET-00028 are done for accepted package implementation and nonvisual QA. Independent Review ACCEPT
and QA PASS cover candidate `b9db457d68e46cddbdde738d7e241fbc2b9bd891`; publication and final delivery were pending
at this checkpoint, with current results in `.runs/handoffs/TASK-00088/land.md`. [Its guide](../docs/agent-continuation-authorization.md)
owns composition; actual consumer scope/handoff/fence qualification stays separate.
TASK-00103 intake verified PR #142 merged/integrated in `develop` at `14eae522213d03c936cd03b34c1f8ef54ed81487`
on 2026-10-27T22:18:27Z, from published head `8d24b2b177208a8218a2258cf39fa2e87dac15d0`. Its marker is retired;
no TASK-00088 review/publication/merge action remains. The pending-at-checkpoint wording above is historical.
TASK-00103's shared PSR-15 admission and separately qualified Slim/Yii 3 journeys have independent Review ACCEPT and QA PASS on candidate `2807785e332187a36299c08ce3826e9fed585729`. Publication and final delivery were pending at closeout; current results are in `.runs/handoffs/TASK-00103/land.md`. See [HTTP composition](../docs/http-permission-admission.md).
TASK-00104 intake verified TASK-00103 PR #143 MERGED into `develop` at
`d4fda9b41fff0dde922c7dae569ee71805d0e358` on 2026-10-28T12:10:37Z, published head
`87e3d640aaf882c58dddfe2effbefa5c6fe05cf5` integrated in that selected base. Its marker is retired;
no TASK-00103 review/publication/merge action remains. The pending-at-closeout wording above is historical.
TASK-00104 has independent Review ACCEPT and behavioral QA PASS on candidate
`fb25934087f931527325c3d66aa49e2b83a944eb`. Optional native Symfony admission and its limits are described in the
[Symfony guide](../docs/symfony-permission-admission.md). Publication and final delivery were pending at closeout;
current result: `.runs/handoffs/TASK-00104/land.md`. Actual consumer qualification remains separate.
The remaining EPIC scope and combined release remain unfinished. The planning history below retains its original checkpoint.

### Planning history

[EPIC-00014 — Permission-Aware MCP Integration and Framework Adapters](epics/00014-EPIC.md) now owns the single
approved destination from the [closed Wayfinder map](wayfinder/mcp-capability-permission-integration-map.md).
All four decisions and the map's EPIC phase are complete. The accepted combined release includes Tool/execution,
Resource/Skill/Prompt, Subscriptions and durable Task permission integration, Common-compatible safe errors and all
five framework families (Slim, Yii 3, Symfony, Laravel, CodeIgniter 4), with optional capability-first `src/Adapter`
integrations and consumer-owned policy/scope. The release number remains unassigned.

John approved the full eight-TICKET split, now written as [TICKET-00025](tickets/00025-TICKET.md) through
[TICKET-00032](tickets/00032-TICKET.md). All map EPIC/TICKET phases are complete. John subsequently selected
TICKET-00025 for To Tasks and approved its single-TASK plan, now written as [TASK-00084](tasks/00084-TASK.md).
It is `needs-info` for actual qualified/tagged Common execution contracts and minimum-version evidence, with no
unfinished local TASK blockers. John subsequently approved TICKET-00026's complete two-TASK plan, written as
[TASK-00085](tasks/00085-TASK.md) for Resources and [TASK-00086](tasks/00086-TASK.md) for whole Skills/member reads.
TASK-00085 has completed its accepted Resource implementation and required package verification; [PR #139](https://github.com/johnnickell/fight-access-control/pull/139)
is open against `develop`. TASK-00086 depends only on TASK-00085, not TASK-00084. Qualifying its actual supported
Common minimum/behavior remains an implementation obligation, not a missing execution-Tool API hold.
John then approved TICKET-00027's complete single-TASK plan, written as [TASK-00087](tasks/00087-TASK.md), needs-info
for qualified/tagged Common Prompt contracts and supported minimum evidence, with no unfinished local TASK blockers.
John then approved TICKET-00028's single-TASK plan, written as [TASK-00088](tasks/00088-TASK.md), ready-for-agent
with no local TASK blocker or new Common runtime API prerequisite. It owns trusted binding/current checks and
synchronized protected admission, with actual consumer lifetime/scope/fence qualification separate.
John then approved TICKET-00029's full plan, written as [TASK-00089](tasks/00089-TASK.md) for scoped timeline reads
and [TASK-00090](tasks/00090-TASK.md) for independent protected metadata/body reads. Both consume TASK-00084 and are
needs-info for qualified/tagged Common scoped-read contracts/minimum evidence; neither depends on the other or on
TASK-00088 for ordinary requests. Consumer scope/persistence/race qualification remains separate.
John then approved TICKET-00030's six complete journeys, written as [TASK-00091](tasks/00091-TASK.md) recording/removal,
[TASK-00092](tasks/00092-TASK.md) retained inspection/recovery, [TASK-00093](tasks/00093-TASK.md) live Tool/Resource/Skill
disclosure, [TASK-00094](tasks/00094-TASK.md) Prompt signals, [TASK-00095](tasks/00095-TASK.md) subscriber inspection and
[TASK-00096](tasks/00096-TASK.md) optional publication. All six are needs-info for qualified/tagged Common contracts/
minimum evidence, separately from approved local supplier edges. Publication is independent of live/Prompt/inspection,
Prompt absence does not block ordinary live families, and polling needs no live runtime.
John then approved TICKET-00031's six complete features, written as [TASK-00097](tasks/00097-TASK.md) creation/producer/
protected reads, [TASK-00098](tasks/00098-TASK.md) removal, [TASK-00099](tasks/00099-TASK.md) input/continuation,
[TASK-00100](tasks/00100-TASK.md) cancellation/intent, [TASK-00101](tasks/00101-TASK.md) complete MCP start/reconnect and
[TASK-00102](tasks/00102-TASK.md) optional full-state notifications. All six retain needs-info qualified/tagged Common
contract/minimum holds plus approved supplier edges. Ordinary polling/input/cancel need no live runtime, removal does
not block complete activation, and full-state live has no extra Prompt/inspection/Mercure blocker.
John then approved TICKET-00032's full four-feature plan, written as [TASK-00103](tasks/00103-TASK.md) shared PSR-15
with separate Slim/Yii 3 qualification, [TASK-00104](tasks/00104-TASK.md) Symfony,
[TASK-00105](tasks/00105-TASK.md) Laravel and [TASK-00106](tasks/00106-TASK.md) CodeIgniter 4. All are ready-for-agent:
TASK-00103 has no local blocker; B–D consume only its inward shared contract/first Adapter wiring, not Slim/Yii runtime,
and share equal order 104 without relative native framework priority. No inherited MCP/TASK-00088 prerequisite;
actual sufficient tagged minima/native qualification remain acceptance work. All map decisions/EPIC/TICKET/full TASK
plans are accepted/written (TASK-00084–00106, 23 features). Next: `/skill:next` for Board-based selection, not automatic
execution or release. Only TASK-00085's accepted Resource slice is implemented; the remaining EPIC scope is not.
Required Common implementations, independent acceptance/qualification, tagged APIs and actual minimum-version/framework
evidence gate affected adoption/release, not this planning handoff. The
[research](wayfinder/research/fight-common-mcp-permission-integration.md) preserves initial source snapshots;
the EPIC records current local maturity observations without consumer qualification.
John subsequently authorized commit, push and a planning-only PR to develop. No grants, dependency changes,
implementation, merge, release or consumer adoption are authorized by that request. The published v0.8.0 handoff
below is unchanged.

## Current release handoff — v0.8.0 published

[v0.8.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.8.0) is published on 2026-10-21 from
certified main merge `319738255e682d587642b67856039f882b9588d6` through
[PR #134](https://github.com/johnnickell/fight-access-control/pull/134). Independent release-content acceptance,
documentation QA, fresh exact-main certification, human-signed local/remote tag, immutable latest/non-prerelease
GitHub publication and matching Packagist source/dist projection are verified. The
[release overview](../docs/release-0.8.0.md) owns identities, breaking Role/recovery composition and unexecuted consumer
qualification. PHP 8.5+ and Common ^1.3 remain unchanged.

Main-to-develop merge-back completed through [PR #135](https://github.com/johnnickell/fight-access-control/pull/135)
on 2026-10-21 at `d955d2f88cf58a5696814adeebb609a64fff8ee9`. Release-owned cleanup is satisfied: both
`release/0.8.0` and `release/0.8.0-merge-back` are absent locally and on origin, with no isolated release worktree.
The release ledger retains the verified closeout and evidence; no release-owned action remains. Preserve the signed
release commit and evidence.
At v0.8.0 release closeout no implementation TASK remained unfinished; the new future-integration TASK-00084–00106 records are
tracked above. v1.0.0 stability, consumer adoption and deployment remain separate decisions.

### Historical v0.8.0 preparation checkpoint

John selected v0.8.0 and the main checkout on 2026-10-21. Preparation used `release/0.8.0` from refreshed `develop`
`6ea05dc`, including merged [PR #133](https://github.com/johnnickell/fight-access-control/pull/133). At that checkpoint,
v0.7.0 was the latest published release; independent release-content acceptance, main integration, exact-main
certification, human signing and publication were still required. The current handoff above supersedes those pending
steps without erasing their historical checkpoint meaning.

## Correction batch integrated

The [correction map](wayfinder/access-control-correction-batch-map.md)'s decisions and decomposition are complete.
[EPIC-00001](epics/00001-EPIC.md) retains existing requirement ownership through
[TICKET-00001](tickets/00001-TICKET.md#approved-correction-batch-amendment), plus new
[TICKET-00024](tickets/00024-TICKET.md) for Role caller revisions.

The accepted TASKs are [TASK-00077](tasks/00077-TASK.md) for operation outcomes,
[TASK-00078](tasks/00078-TASK.md) for recovery lifecycle, [TASK-00079](tasks/00079-TASK.md) for policy consistency
and [TASK-00080](tasks/00080-TASK.md) for Role caller revisions. TASK-00078/00079 depend on TASK-00077;
TASK-00080 depends on TASK-00077/00079. TASK-00078 precedes TASK-00079 by priority only.

The map is Closed for planning. TASK-00077–00079 are complete and integrated. TASK-00080 has independent technical
acceptance and nonvisual behavioral QA on its exact implementation candidate; its sole-child TICKET-00024 is done through
automatic parent completion while EPIC-00001 retains its historical terminal status. Its [current Role composition](../docs/role-revisions.md)
and saved local gates are package evidence, not consumer qualification. Public summaries do not replace approved maintainer
briefs or authorize restricted disclosure. [TASK-00080 PR #132](https://github.com/johnnickell/fight-access-control/pull/132)
merged into `develop` on 2026-10-21 at `d1ed655`; the correction batch has no remaining decomposition,
implementation or PR-merge handoff. Consumer qualification, version selection, release and deployment remain separate.

[TASK-00082](tasks/00082-TASK.md) completed the README, identity assets, release-version consistency and planning
reconciliation; PR #133 merged into `develop` on 2026-10-21. Its earlier v0.8.0 recommendation is now superseded by
John's version selection above. The breaking pre-v1 Role and recovery contracts require a new minor, not a v0.7.x
patch. The [Board](tasks/BOARD.md) owns the current TASK portfolio.

## Published v0.7.0

[v0.7.0](https://github.com/johnnickell/fight-access-control/releases/tag/v0.7.0) was published on 2026-10-20
from certified main merge `cf55a25` through [PR #124](https://github.com/johnnickell/fight-access-control/pull/124).
Independent release-content acceptance, exact-main certification, human signed tag, immutable non-prerelease
GitHub publication (latest at its publication checkpoint) and matching Packagist source/dist projection are verified. The
[release overview](../docs/release-0.7.0.md) owns the breaking contracts and consumer obligations. Merge-back into
`develop` completed through [PR #125](https://github.com/johnnickell/fight-access-control/pull/125) on 2026-10-20.
The ignored release ledger retains cleanup evidence; branch cleanup is not inferred from merge state.
The [Board](tasks/BOARD.md) owns current implementation status. Consumer qualification, enablement/adoption and
deployment, and the v1.0.0 stability decision, remain separate.

### Historical v0.7.0 preparation checkpoint

John selected **v0.7.0** and the main checkout on 2026-10-20. Refreshed `develop` `4ffc31b` contains the complete
independently accepted registration capability integrated through PRs #121–#123. The
[release overview](../docs/release-0.7.0.md) covers durable activation origin, atomic default-Role registration,
authorized administrator recovery and the required breaking consumer contracts. PHP 8.5+ and Common ^1.3 remain
unchanged; release preparation changes documentation only. Independent release-content acceptance, release PR/main
integration, exact-merge certification, human signing and separately authorized publication remain required.
No implementation TASK is unfinished. The EPIC's pre-v1.0.0 planning destination does not select v1.0.0 or waive
its separate stability review; consumer qualification, enablement and deployment remain separate.

The v0.6.0 release was published on 2026-10-19 and merged back through
[PR #120](https://github.com/johnnickell/fight-access-control/pull/120). Earlier preparation and implementation
checkpoints below retain their historical meaning; the Board owns current implementation status.

### Historical v0.6.0 preparation checkpoint

John selected **v0.6.0** and the main checkout on 2026-10-19. Integrated `develop` `cc364511` includes merged
[PR #118](https://github.com/johnnickell/fight-access-control/pull/118) and accepted
[TASK-00076](tasks/00076-TASK.md). The [release overview](../docs/release-0.6.0.md) documents expiry-aware invitation
retry and the breaking required Clock constructor argument. No runtime/test/dependency change is part of release
preparation. Independent release-content acceptance, main integration, exact-merge certification, human signing and
separately authorized publication remain required. Optional self-registration TASK-00073–00075 remains planned
v1.0.0 work; the Board owns its execution selection and it does not block this bounded release.

The v0.5.0 release was published on 2026-10-06 and merged back through PR #115. The sections below preserve prior
implementation/release-readiness checkpoints, not current publication or portfolio state. Package release does not
establish actual consumer qualification, dependency adoption or deployment.

### Historical v0.5.0 preparation checkpoint

John selected **v0.5.0** and the main checkout on 2026-10-06. Integrated `develop` `4163e58` contains the accepted
Agent-operation, human-expiry, Feature and Agent-profile/MCP work; no implementation TASK remains open. The
[release overview](../docs/release-0.5.0.md) owns the combined contract and consumer obligations. Dated release notes
are preparation, not a release receipt: independent release-content review, integration into `main`, exact-merge
certification, human signing and separately authorized publication remain outstanding at this checkpoint.

Earlier implementation/landing checkpoints below retain their historical meaning. Actual consumer qualification,
dependency adoption and deployment remain separate from package acceptance and release.

## Human delivery expiry readiness prerequisite

The required [human credential-delivery expiry capability](wayfinder/human-credential-delivery-expiry-map.md) now
has approved upstream records, complete implementation/local verification, independent acceptance and applicable
behavioral QA. This package prerequisite for v0.5.0 is satisfied; final release certification is still required.
[WF-025](wayfinder/tickets/WF-025-human-delivery-expiry-contract.md) is resolved: invitation/reset delivery expiry plus
full email-change authority/reservation expiry, including already-terminal delivery and changed User account states.
John confirmed [EPIC-00012 — Package-Owned Human Credential Expiry Cleanup for v0.5.0](epics/00012-EPIC.md); its only
Wayfinder destination is written and the map is Closed. John approved its single cohesive requirement area, now
[TICKET-00020](tickets/00020-TICKET.md), retaining the entire WF-025 matrix, then approved its sole implementation slice,
[TASK-00072](tasks/00072-TASK.md). Full EPIC/TICKET/TASK decomposition is accepted and complete; Board execution selection,
implementation authority and placement were separately resolved for TASK-00072: John selected main and requested
inclusion of pending planning changes in its commit. At the pre-publication checkpoint, the implementation passes
206 focused tests / 2957 assertions and the complete gate (1993 tests / 37644 assertions), exact 6841/6841 product
statements and 971/971 changed-production statements. Subsequent revised candidate
`9bdf3822eb5f97505cca8bc76f2f1a14dc5d28d0` has independent technical acceptance (F1–F3 resolved, no findings) and
post-review nonvisual QA PASS: six scenario groups, 86 driver cases / 795 checks and 357 conformance tests / 5780 assertions.
Its accepted complete gate passes 2019 tests / 39818 assertions, exact 6841/6841 owned statements, no final warnings/skips.
TASK-00072 is done for accepted package implementation/local verification; automatic parent completion closes TICKET-00020
and EPIC-00012 in the same closeout. This package readiness prerequisite is satisfied, not release certification or actual
consumer qualification. John authorized landing; final PR publication verification is pending at this tracked checkpoint.
The TASK's ignored landing receipt owns fresh checks, administrative-only provenance and actual PR/remote identity.
Before this implementation, due discovery could not clean recoverable invitation/reset
work after downtime through grant expiry; the unchanged due path now has separate expired discovery/direct cleanup. Email reservations must not remain stranded because delivery finished or the account
became inactive; no consumer product/provider wiring is enabled. This follow-up does not reopen completed EPIC-00009
or its original delivery predecessors. Agent OS `TASK-00024` is the external blocked consumer work, not an AccessControl
dependency edge. Package proof must be distinguished from actual PostgreSQL consumer qualification/adoption. Tagging,
signing, pushing and publication remain separately authorized; the Board still owns execution priority, unchanged by
this approved decomposition.

## Historical Agent package acceptance checkpoint

John's 2026-09-30 package-wide decision in [ADR 0011](adr/0011-pre-v1-current-contract-only.md) removes all obligations
to support previous APIs/data iterations while pre-v1. [TASK-00068](tasks/00068-TASK.md) has accepted removal of
legacy-Agent mode/API stubs and credential-delivery compatibility defaults. TASK-00058's current-contract restoration
and TASK-00059's final integration guidance/evidence are independently accepted; TASK-00059 behavioral QA also passes.
John authorized TASK-00059 landing, with publication pending at this tracked checkpoint. Real consumer evidence remains
open; no migration, release or deployment is claimed. The [Board](tasks/BOARD.md) owns next executable work.

### Original decomposition context

[EPIC-00009](epics/00009-EPIC.md) prepares recoverable Agent provisioning and rotation from the accepted-for-planning
Agent OS proposal. The breaking replacement, ADR 0004/WF-002 amendment, transactional authorization/protected-sink
participation, scoped post-commit result behavior and amended bounded policy are ratified. Finite defaults and optional
validated overrides bound credential operations; capacity handling preserves existing-operation recovery. Committed
issuance does not establish delivery, enrollment activation or launch authority. John confirmed the shared destination
and boundaries on 2026-09-27, then approved the decomposition into
[TICKET-00012](tickets/00012-TICKET.md) (issuance/resolution),
[TICKET-00013](tickets/00013-TICKET.md) (protected delivery/recovery) and
[TICKET-00014](tickets/00014-TICKET.md) (migration/compatibility and evidence traceability).

At the approved decomposition checkpoint, TICKET-00012's TASK split was recorded:
[TASK-00046](tasks/00046-TASK.md) was the first ready provisioning slice;
[TASK-00047](tasks/00047-TASK.md) reads status after it. [TASK-00048](tasks/00048-TASK.md) (rotation) and
[TASK-00049](tasks/00049-TASK.md) (conformance) now have their approved TICKET-00013 dependencies recorded and remain
waiting, not needs-info. TICKET-00013's [TASK-00050](tasks/00050-TASK.md)–[TASK-00054](tasks/00054-TASK.md) cover
retirement fences, protected delivery, restart recovery, material maintenance and delivery/lifecycle conformance.
They begin after TASK-00046 through an acyclic graph; neither conformance TASK blocks its implementation inputs.
TICKET-00014's [TASK-00055](tasks/00055-TASK.md)–[TASK-00059](tasks/00059-TASK.md) now cover legacy compatibility,
cohort enforcement, canonical upgrades, restoration safety and final migration/evidence guidance. All three TICKETs
have approved TASK splits; at that checkpoint TASK-00046 was first ready and TASK-00047–00059 waited on dependencies.
Concrete limits and compatibility details remain design/proof obligations before implementation acceptance. Planning readiness is
not execution authorization; intermediate PRs are not a supported partial release or consumer qualification.
John selected `v0.5.0` as the target release. Implementation, release/publication, consumer upgrade and Agent OS
TASK-00138 closure remain separately authorized operations.

Current package checkpoint: TASK-00056's cohort safety and revised TASK-00057's single canonical contract are
independently accepted, as are TASK-00068 removal (revised M1), TASK-00058 restoration (M4) and TASK-00059 guidance
(M5). TASK-00055's legacy preservation is superseded, not a continuing obligation. Real consumer qualification remains
outstanding; package acceptance does not close the parent consumer obligations. The [Board](tasks/BOARD.md) owns
current executable ordering.

## MCP package acceptance

Fight Common ^1.3 supplies the released Tool API; inspected v1.3.0 revision/signatures and limits are recorded in
[TASK-00035](tasks/00035-TASK.md#verified-fight-common-v130-contracts). TASK-00035 and TASK-00069–00071 now have
independent technical acceptance and behavioral QA and are integrated through PR #113. EPIC-00005/00011 and their
TICKETs are done. Both destinations are included in v0.5.0 preparation; no TASK is waiting on the former Common gate.
The related Wayfinder map remains Closed. Actual consumer MCP runtime, security, seed/grant and persistence
qualification remain separate; package acceptance is not publication or adoption.

## Feature package acceptance

[EPIC-00010 — Permission-Based Feature Flags](epics/00010-EPIC.md) has an approved three-TICKET decomposition from
its closed [Wayfinder map](wayfinder/permission-based-feature-flags-map.md):
[TICKET-00015](tickets/00015-TICKET.md) establishes registration/provisioning,
[TICKET-00016](tickets/00016-TICKET.md) defines fresh Permission-based availability for Users and Agents, and
[TICKET-00017](tickets/00017-TICKET.md) defines management and guarded retirement. The latter two build on the first's
shared contracts; each includes its evidence and consumer integration obligations. Consumers separately own
persistence, scanning, enforcement, UI, and deployment; actual CMS adoption is not included.

TICKET-00015's approved chain is [TASK-00061](tasks/00061-TASK.md) (declarations/inventory) →
[TASK-00062](tasks/00062-TASK.md) (atomic provisioning) → [TASK-00063](tasks/00063-TASK.md) (preparation validation and
reusable contract scenarios). John approved method-only/nonrepeatable Attributes and one transaction per provisioning
pass, with fresh-state retries and no promise of reliable event delivery. TICKET-00016's approved
[TASK-00064](tasks/00064-TASK.md) depends only on TASK-00062 and delivers the complete fresh, ID-safe evaluator with
existing User/Agent snapshots or null, boolean availability, and distinguishable errors. No shared authority-interface
change is needed. TICKET-00017 now has approved [TASK-00065](tasks/00065-TASK.md) (Permission reference protection,
after 00062), [TASK-00066](tasks/00066-TASK.md) (management, after 00065/00064), and
[TASK-00067](tasks/00067-TASK.md) (guarded retirement and final lifecycle evidence, after 00066/00063).
John approved shared revision-1 creation, increments only on real status/Permission changes, expected revisions for
updates/deletion, and explicit broken-binding reads using existing pagination. Consumer confirmation and authorization
remain separate. At the decomposition checkpoint all three TICKETs and seven TASKs were planned; that planning
handoff alone authorized no implementation, release target or execution worktree.

All seven TASKs subsequently obtained independent technical acceptance and behavioral QA. TASK-00067's clean
accepted candidate `8e33f0847d41aff562e6258b1a360d70d152dfe1` supplies actual guarded retirement/reintroduction
and final traceability; QA passes seven scenarios / 260 executable checks plus an instruction walkthrough.
Its accepted full gate passes 1849 tests / 35482 assertions, exact 6627/6627 statements. Automatic parent
completion closes TICKET-00017 and EPIC-00010 in the same TASK closeout; TICKET-00015/00016 are already done.
Package acceptance is complete, not actual consumer scanner/database/UI/security/runtime qualification. John
authorized TASK-00067 landing; publication remained pending at that tracked checkpoint. Its PR #107 is now merged
into the selected release baseline. The complete Feature capability is included in v0.5.0 preparation; certification,
publication, actual consumer adoption and deployment remain separate.

## Optional self-registration accepted for v0.7.0

[EPIC-00013 — Optional User Self-Registration and Registration-Origin Delivery for v1.0.0](epics/00013-EPIC.md)
records John's approved opt-in package destination after the
[Wayfinder map](wayfinder/self-registration-v1-map.md) closed WF-026–028. Consumers own enablement, public protections
and email templates; package registration must keep atomic uniqueness/default Roles and persist registration versus
private-invitation delivery origin through recovery. No v0.5.0 scope or default Agent OS/starter features change.
John approved the three-area requirement decomposition: [TICKET-00021](tickets/00021-TICKET.md) (delivery origin) →
[TICKET-00022](tickets/00022-TICKET.md) (registration) → [TICKET-00023](tickets/00023-TICKET.md) (administrator
recovery). John approved one TASK per requirement area:
[TASK-00073](tasks/00073-TASK.md) (origin/provider) → [TASK-00074](tasks/00074-TASK.md) (atomic registration) →
[TASK-00075](tasks/00075-TASK.md) (authorized correction and expired reissue). All three tasks are accepted with
applicable nonvisual QA and integrated through PRs #121–#123; final
[PR #123](https://github.com/johnnickell/fight-access-control/pull/123) is merged into `develop` `4ffc31b`.
Automatic parent completion closed TICKET-00023 and EPIC-00013. The complete capability is now published in
[v0.7.0](../docs/release-0.7.0.md); consumer integration/enablement and deployment remain separate.
The EPIC's historical pre-v1.0.0 target is not an authorization to release v1.0.0.

## Route to 1.0.0

1. Publish completed framework-neutral capabilities as reviewed pre-`1.0.0` package releases, beginning with
   `v0.1.0`, without making full starter implementation a package-release prerequisite.
2. Implement the full Symfony, Laravel, Yii,
   CodeIgniter, and Slim starter skeletons against tagged package versions.
3. Feed shared compatibility findings into reviewed subsequent `0.x` releases while framework-specific fixes
   remain in their owning starter repositories.
4. Run a separate stability review before authorizing a `1.0.0` release.
5. Keep implementation, commit, push, pull request, merge, release, and publication as separate approvals.

## Completed

| Epic | Target | Outcome |
| --- | --- | --- |
| [EPIC-00012](epics/00012-EPIC.md) | `v0.5.0` | Human expiry accepted with behavioral QA and integrated through PR #108; the package release prerequisite is satisfied. Actual PostgreSQL/consumer qualification, adoption, certification/publication and deployment remain separate. |
| [EPIC-00011](epics/00011-EPIC.md) | `v0.5.0` | Agent rename, minimal profiles and protected self-service Tools accepted with behavioral QA and integrated through PR #113; consumer qualification remains separate. |
| [EPIC-00010](epics/00010-EPIC.md) | `v0.5.0` | All seven Feature TASKs accepted with behavioral QA; final lifecycle evidence integrated through PR #107. Consumer qualification and publication remain separate. |
| [EPIC-00009](epics/00009-EPIC.md) | `v0.5.0` | Agent operations, current-contract cleanup and cohort/canonical/restoration safety accepted; final guidance has independent acceptance and QA. Real consumer qualification and release remain separate. |
| [EPIC-00005](epics/00005-EPIC.md) | `v0.5.0` | Agent-protected MCP authorization accepted with behavioral QA and integrated through PR #111; consumer runtime qualification remains separate. |
| [EPIC-00006](epics/00006-EPIC.md) | `v0.3.0` | Published the recoverable credential-delivery contract and migration after exact-commit certification and signing. Consumer upgrades remain separately qualified. |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | Replaced deprecated Fight Common transaction dependencies with the supported `TransactionalUnitOfWork` contract while preserving established behavior. |
| [EPIC-00004](epics/00004-EPIC.md) | `v0.2.0` | Delivered the local consumer-composable OpenAPI schema review candidate; tag, release, and publication remain separate effects. |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | Delivered the final unified User/Agent `SecurityContext` and retry-safe Agent Permission, User Role, and custom-Role Permission changes. |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

| Version | Date | Outcome |
| --- | --- | --- |
| `v0.8.0` | 2026-10-21 | Published breaking Role caller revisions and recovery composition, operation-outcome and managed-policy consistency, plus contributor/release-documentation improvements. Main-to-develop merge-back completed through PR #135; release-owned cleanup is satisfied. Consumer qualification remains separate. |
| `v0.7.0` | 2026-10-20 | Published optional User registration, activation origin and administrator recovery; merged back through PR #125. Consumer enablement and qualification remain separate. |
| `v0.6.0` | 2026-10-19 | Published expiry-aware invitation retry and the required Clock composition change; merged back through PR #120. |
| `v0.5.0` | 2026-10-06 | Published recoverable Agent operations, human expiry, Features and Agent-protected profile Tools; consumer qualification remains separate. |
| `v0.4.0` | 2026-09-27 | Published protected Permission tiers, reserved Super Admin identity and atomic eligible delegation/reconciliation; consumer integration remains separately qualified. |
| `v0.3.0` | 2026-09-25 | Published recoverable, provider-neutral credential delivery for invitations, password resets, and email changes, with pre-1.0 breaking migration guidance. |
| `v0.2.0` | 2026-09-13 | Published consumer-composable OpenAPI schema components. |
| `v0.1.0` | 2026-09-10 | First public package milestone: framework-neutral User and Agent identity, authentication, session, Role, Permission, managed-policy, and current-authority behavior with exact coverage. |

## Record Status Projection

<!-- generated:epic-status:start -->
| EPIC | Target | Status | Tickets | Tasks |
| --- | --- | --- | --- | --- |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | done | 2 | 22 |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | done | 1 | 7 |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | done | 2 | 6 |
| [EPIC-00004](epics/00004-EPIC.md) | v0.2.0 | done | 1 | 1 |
| [EPIC-00005](epics/00005-EPIC.md) | v0.5.0 | done | 1 | 1 |
| [EPIC-00006](epics/00006-EPIC.md) | v0.3.0 | done | 1 | 3 |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | done | 1 | 1 |
| [EPIC-00008](epics/00008-EPIC.md) | v0.4.0 | done | 3 | 5 |
| [EPIC-00009](epics/00009-EPIC.md) | v0.5.0 | done | 3 | 14 |
| [EPIC-00010](epics/00010-EPIC.md) | v0.5.0 | done | 3 | 7 |
| [EPIC-00011](epics/00011-EPIC.md) | v0.5.0 | done | 2 | 3 |
| [EPIC-00012](epics/00012-EPIC.md) | v0.5.0 | done | 1 | 1 |
| [EPIC-00013](epics/00013-EPIC.md) | v1.0.0 | done | 3 | 3 |
| [EPIC-00014](epics/00014-EPIC.md) | unassigned | ready-for-agent | 8 | 23 |
| [EPIC-00015](epics/00015-EPIC.md) | unassigned | done | 5 | 9 |
| [EPIC-00016](epics/00016-EPIC.md) | unassigned | ready-for-agent | 6 | 31 |
| [EPIC-00017](epics/00017-EPIC.md) | unassigned | ready-for-agent | 6 | 24 |
| [EPIC-00018](epics/00018-EPIC.md) | unassigned | ready-for-agent | 4 | 12 |
<!-- generated:epic-status:end -->
