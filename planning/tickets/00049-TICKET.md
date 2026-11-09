---
id: TICKET-00049
epic: EPIC-00018
title: Declare effective action requirements
status: ready-for-agent
---

# Declare effective action requirements

## Problem and Outcome

Under [EPIC-00018](../epics/00018-EPIC.md), consumers need the same validated Feature and Permission declarations for
HTTP action admission and Feature reference discovery. Current FeatureFlag is method-only/nonrepeatable, AccessControl
has no portable RequiresPermission, and merely changing PHP Attribute flags would not implement inherited requirements.
Deliver framework-neutral effective declarations with one truthful meaning, without runtime enforcement or a scanner.

## Use Cases and Contracts

| Actor and trigger | Observable outcome |
| --- | --- |
| Application author declares action restrictions | FeatureFlag and AccessControl-owned RequiresPermission support repeated class/method declarations with validated canonical names. |
| Trusted routing selects an action with parent/overridden declarations | Effective class, method and inherited requirements accumulate; child declarations can add but cannot weaken gates. |
| Integrator supplies explicit route requirements | Merge with attributes conjunctively, deduplicate identical valid requirements and reject contradictions rather than choose an override. |
| Consumer inventories candidate/current Feature references | Include effective class/repeated/inherited and registered names without claiming partial discovery is complete. |

No business Command, Query, Domain Event, repository mutation or audit evidence is needed for declaration/merging.
These are pure metadata contracts, not an authorization decision. Raw secrets and transport data do not belong in them.

## Requirements and Validation

- Extend FeatureFlag and introduce package-owned portable RequiresPermission for classes and methods, both repeatable.
  Preserve validated FeatureName/PermissionName semantics; do not require Agent OS's consumer Attribute or invent a
  compatibility alias. Exact cohesive public namespace/signature/value choices remain design work within this boundary.
- Resolve effective declarations for the actual selected HTTP action, including supported inherited/invokable and
  overridden-method forms. Walk applicable class/method ancestry deliberately; PHP reflection/target flags alone do
  not establish inheritance semantics. Maintain route/action association and never infer targets from client input.
- Accumulate class, method, inherited and explicit trusted route requirements conjunctively. Validate every declaration
  before deduplication so a duplicate cannot conceal malformed input. Deduplicate the effective valid set without
  weakening raw-input validation or silently changing unrelated existing PermissionDeclaration constructor rules.
- Reject contradictory, malformed or ambiguous protected metadata; absence/unsupported resolution cannot become an
  unguarded protected action. Explicit PUBLIC/AUTHENTICATED-only Permission policy cannot erase RequiresPermission.
  A PUBLIC Permission route may legitimately have Feature requirements; the capabilities remain independent.
- No effective Feature declaration means no Feature gate, not public Permission access. Preserve deliberate existing
  PUBLIC/AUTHENTICATED/PROTECTED rules and inward Permission contracts; no absent-Attribute public fallback, new grants,
  role mapping or automatic Symfony voter is introduced. Runtime refusal and principal resolution belong to the
  capability tickets, not these metadata values.
- Align necessary FeatureReferences/discovery contracts, current consumers/callers/examples and fixtures with class,
  repeated and inherited declarations. Preserve complete CANDIDATE versus CURRENT scopes, actual name validation,
  safe retirement references and complete-empty versus unavailable/failed/partial discovery. Consumers still own code
  identity, scanning/registration completeness and freshness; the package supplies no production scanner/cache.
- Validate native placement/multiplicity/arguments through actual Attribute instantiation, not getArguments() alone.
  Existing wrong-target/repetition expectations must reflect the new current contract while unsupported targets still
  reject. Direct PHP invocation remains unintercepted; declarations do not provision, authorize or mutate a Feature.
- Deliver necessary coherent current contract/caller/fixture/documentation/Unreleased adaptations together, preserving
  prior historical acceptance as history rather than proof of new declaration or discovery behavior.

## Dependencies and Existing Work

This shared requirement supplies [TICKET-00050](00050-TICKET.md) Feature admission and [TICKET-00051](00051-TICKET.md)
portable Permission integration. It does not enable either capability by itself or force their mutual activation.
[TICKET-00052](00052-TICKET.md) owns cross-capability/native reconciliation, not this ticket's own complete tests/docs.
The approved TASK plan below assigns the actual declaration suppliers, without fake TASK IDs or a blanket
Common/architecture wait.

Coordinate existing TICKET-00015/EPIC-00010 Feature contracts, TASK-00149 declarative metadata qualification and actual
EPIC-00015/00016 ownership without duplicating redesign/typing or expanding their scope/order/blockers. Reuse inward
PermissionDeclaration/admission rather than introduce a second permission policy engine. No new Common mechanism is
demonstrated; verify actual tagged/supported APIs and minima at execution and hold only a genuine affected gap.
Apply ADR 0011 without legacy readers/modes/backfill; Common retains its own compatibility policy. Obtain applicable
complete authorized private criteria before implementation/review without disclosure; choose the execution checkout
explicitly and preserve unrelated work.

## Acceptance Evidence

- [ ] Both portable Attributes validate canonical names and native class/method/repeatable placement, while unsupported
  targets/arguments reject and direct method calls remain unenforced.
- [ ] Real selected-action hierarchies, inherited/overridden methods, class/method/route additions, duplicates and
  contradictory/malformed/ambiguous inputs prove one effective conjunctive meaning with no child weakening or fallback.
- [ ] Feature reference/discovery/provisioning callers account for effective new declaration locations and preserve
  CANDIDATE/CURRENT, complete-empty versus unavailable and actual caller/scanner qualification limits.
- [ ] Current metadata/callers/examples are coherent without a parallel policy, scanner, legacy shim or runtime admission
  claim. Permission declaration/public-route rules and independent capability selection remain intact.
- [ ] Each executable slice retains focused behavior/static checks, meaningful regression-first defect tests, current
  docs, canonical full gate/exact owned statement coverage, complete evidence and independent Review/behavioral QA.

## Exclusions

Native admission, principal/authentication policy, Feature evaluation redesign, new grants, production scanner/cache,
arbitrary PHP-call interception, MCP/CLI/worker enforcement, production persistence or consumer bootstrap/schema/data
changes, sibling/Common repair, compatibility/migration/backfill, release/version selection and deployment. Existing
RequiresAgentPermission/MCP bindings are not replaced. Package metadata fixtures are not consumer scanner/runtime proof.

## Approved TASK Plan and Ownership

John approved two complete declaration paths, now written as ordinary ticketed feature TASKs rather than a partial
Attribute-flags/merger/discovery layer split:

| TASK | Order | Complete delivered outcome | Direct blockers |
| --- | --- | --- | --- |
| [TASK-00170](../tasks/00170-TASK.md) | 501 | Effective Feature declarations through scoped discovery/preparation/provisioning/retirement callers, including one small shared pure selected-action/ancestry mechanism. | None |
| [TASK-00171](../tasks/00171-TASK.md) | 502 | Portable RequiresPermission and effective conjunctive PermissionDeclaration composition, consuming that same pure selected-action/ancestry output. | TASK-00170 |

TASK-00170 is now independently accepted with nonvisual QA and done; TASK-00171 remains ready-for-agent and can
consume its completed pure traversal supplier. The Permission path is not implemented by Feature acceptance.
Orders are preference beyond that edge, not a blanket wait on architecture/typing/Common/audit or native adapters.
Shared traversal ownership belongs solely to TASK-00170: TASK-00171 reuses it without a copied hierarchy walk or a
runtime dependency on Feature evaluation, discovery providers/repositories or capability activation. Concrete public
names/signatures remain bounded implementation design, not a new generic reflection/security registry.

TASK-00170 keeps native Attribute class/method/repeatable validation, selected-action association, inherited/overridden/
invokable forms and trusted route conjunction coherent with actual FeatureReferences/discovery and directly affected
package callers/fixtures/docs. Validate every declaration before deduplication; no controller construction merely to
inspect metadata, child weakening, ambiguous-target empty fallback or getArguments-only validation. Scope fixtures
must include class and overridden-parent references, not only current method attributes. Preserve CANDIDATE/CURRENT,
complete-empty versus unavailable/partial/invalid discovery, read-only preparation, existing provisioning behavior and
complete current-reference/revision retirement guards. Consumers still own actual scanning/code identity/completeness.

TASK-00171 introduces the package-owned portable Attribute rather than depend on Agent OS's Attribute or replace
RequiresAgentPermission/MCP. Attribute-only requirements yield protected declarations without redundant route repetition;
trusted route additions accumulate. Preserve raw PermissionDeclaration list/mode/type/duplicate rejection, validating
each before effective deduplication. Reject contradictory PUBLIC/AUTHENTICATED-only policy rather than erase required
Permissions. Absent Attribute is not PUBLIC; a PUBLIC Permission route may independently carry Feature gates. Reuse
existing inward policy without principal resolution, new grants/effects or automatic native enforcement.

TICKET-00050 can consume TASK-00170 without waiting for TASK-00171. TICKET-00051 consumes TASK-00171 and its inherited
pure traversal, not runtime Feature admission. Native lifecycle/target extraction/error/authority integration remains
with those capability tickets and actual existing TASK-00103–00106 owners, without duplicating or expanding them.
TICKET-00052 owns final cross-capability/native reconciliation, not deferred slice checks/docs/independent acceptance.
No business Command/Query/Event, metadata-time mutation/audit/transaction, production scanner/cache, arbitrary direct-
call interception or schema/data/consumer operation is introduced by either declaration path.

Each slice retains all necessary current caller/fixture/example/Unreleased changes, focused behavior/static checks,
regression-first proof for discovered defects, actual supported minimum/ordinary composition, canonical full gate/
exact owned production coverage, complete evidence and independent Review/nonvisual behavioral QA. Coordinate actual
TASK-00149, Feature use-case/Domain typing, architecture and audit owners once without duplicate redesign, blanket
supplier waits or changes to existing scope/orders/blockers. Native UoW mixed and matching known-local-callback @var
remain sufficient, not a reason for generic suppliers/wrappers/redundant checks. Apply ADR 0011 without aliases/legacy
readers/modes/migration/backfill; Common keeps its own compatibility policy. Obtain applicable complete authorized
private criteria before implementation/review without disclosure and choose the execution checkout explicitly.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 501 | [TASK-00170](../tasks/00170-TASK.md) | Declare and discover effective Feature requirements | done |
| 502 | [TASK-00171](../tasks/00171-TASK.md) | Declare effective portable Permission requirements | ready-for-agent |
## Decisions and Progress

John approved this requirement in EPIC-00018's complete four-TICKET split, then its complete two-TASK plan now written
as TASK-00170/TASK-00171, orders 501/502. The first has no demonstrated blocker; the second consumes only the first's
shared pure selected-action/ancestry output. Readiness is settled scope, not supplier completion, implementation,
independent acceptance or native/scanner/consumer qualification. Existing Board scope/orders/blockers and TASK-00107
first remain unchanged.

Planning only: no source/dependency/Common/consumer mutation, product build, new checkout/branch/worktree,
commit/publication, release/version selection or deployment follows.

Planning generation/read-only validation passes (240 records, 114 active), alongside documentation links and diff/
new-record whitespace checks. Metadata confirms two ordinary ticketed feature TASKs, orders 501/502, no blocker on
TASK-00170 and only TASK-00170 on TASK-00171. Generated child/frontier/waiting and current handoffs agree; existing
Laravel/CI4/metadata supplier orders/blockers and first Board TASK-00107 are unchanged. At that checkpoint,
TICKET-00050–00052 had no TASK children. These checks validate planning structure/ownership, not source/native/scanner/supplier/consumer correctness;
source/dependency status is unchanged and no product build was run.

TICKET-00050's subsequent complete four-TASK Feature plan is now approved/written as TASK-00172–00175, not implemented/
native qualification. It consumes TASK-00170 without waiting for TASK-00171; declaration scope/orders/blockers remain unchanged.

TICKET-00051's subsequent four-TASK portable Permission plan is now written as TASK-00176–00179, consuming TASK-00171
and actual native suppliers without new runtime Feature activation or changed declaration scope/orders/blockers.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning as 12 TASKs,
not implementation/qualification or a second testing programme. Next: `/skill:next` for Board-based selection. All six
audit TICKETs are already TASK-planned as 24 chores, not implemented/qualified; EPIC-00016's accepted split is also
TASK-planned. Added EPIC-00015 Agent conversion and actual Common supplier planning/qualification remain separate
unfinished handoffs. No earlier Wayfinder phase applies to this standalone destination. Finish selected remaining
planning before Board-based execution selection; no workflow starts from this approval.
