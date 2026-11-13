---
id: TICKET-00045
epic: EPIC-00017
title: Audit Role, membership and Agent administration
status: ready-for-agent
---

# Audit Role, membership and Agent administration

## Problem and Outcome

TASK-00153 and TASK-00154 supply independently accepted custom Role lifecycle and Permission audit insertion.
User Role-membership and Agent administration insertion remain unfinished in their owning TASKs.
Under [EPIC-00017](../epics/00017-EPIC.md), handlers record meaningful committed changes in their ordinary mutation
transaction. Consumers should not need event inspection, repository interception or a business-aware Unit-of-Work
decorator to obtain atomic evidence, and can explicitly omit auditing without claiming durability.

## Use Cases and Contracts

| Authorized caller intent | Commands | Required outcome |
| --- | --- | --- |
| Create, rename or remove a custom Role | CreateCustomRole, RenameCustomRole, RemoveCustomRole | Typed Role evidence with actual incarnation/revisions and truthful creation/removal state. |
| Change a custom Role's Permissions | GrantPermissionToCustomRole, RevokePermissionFromCustomRole | Meaningful relationship evidence; existing validated no-change paths create none. |
| Change a User's Role membership | AssignRoleToUser, RemoveRoleFromUser | Typed User/Role relationship evidence preserving reserved-role and membership policy. |
| Update an Agent or its direct Permissions | UpdateAgent, GrantPermissionToAgent, RevokePermissionFromAgent, ReplaceAgentPermissions | Meaningful Agent identity/assignment evidence, preserving actual revision and complete-set semantics. |

These are 5 + 2 + 4 = 11 CommandHandlers. Existing Commands/Events remain the interaction/fact contracts; Queries
remain read-only and no new audit-query API is needed. Entry adapters still own administrative entry authorization;
actor attribution is not a new permission or a substitute for Domain eligibility and retained target policy.

## Requirements and Validation

- Apply [TICKET-00043](00043-TICKET.md): optional last nullable audit repository, direct typed originating actor,
  complete typed evidence and secret-free action-specific context. Actorless inputs gain optional provenance;
  enabled auditing rejects missing attribution before the first mutation requiring evidence. No audit-only actor
  is newly required when disabled or on a pure no-change pass; existing required actor checks remain.
- Build/add evidence explicitly within the handler's actual transaction after authoritative validation and
  persistence, before commit. Publish existing success facts only after confirmed commit. Audit failure aborts
  staged changes; do not catch it to continue unaudited or infer evidence from a callback's returned Event.
- Preserve Role incarnation, authoritative before/after revisions, caller expected-state/CAS and removal's User/
  reference fences. Creation has no fabricated predecessor and removal no fabricated successor. No-op rename,
  already-held membership/Permission and other legitimate no-change branches preserve validation and emit no new
  mutation evidence. Do not convert correlation IDs into universal deduplication keys.
- Preserve Permission-tier eligibility, protected/managed Role policy, reserved Super Admin rules, authoritative
  User/Agent references, assignment revision/concurrency fences and current Agent lifecycle/cohort admission where
  applicable. Audit attribution neither authorizes System/Anonymous actors to bypass policy nor restores retired
  actor-only administration ports; follow ADR 0010's consumer entry-authorization boundary.
- Reconcile Agent Permission-assignment coordinator transaction ownership so handler coordination inserts evidence
  inside the actual mutation boundary. Reuse cohesive behavior, not forwarding handlers, independent commits,
  nested transactions or a generic lifecycle framework. Do not duplicate evidence for incidental repository writes.
- Preserve primary failure, rollback/uncertain-commit distinctions and ordinary post-commit publisher-failure
  propagation. Committed evidence survives subscriber failure; rejected/rolled-back changes have no committed-
  mutation record. No guaranteed durable rejected-attempt store is introduced.
- Use the parent EPIC's concrete Role example as an ownership illustration, not an already shipped factory API.
  Ordinary shared transactions plus the evolved audit adapter replace the need for business-aware interception.
  Current consumer persistence cannot be assumed to support new actor/subject/revision data without qualification.

## Dependencies and Sequencing

Consume TICKET-00043 and coordinate overlapping shared definitions/callers exactly once. This family can proceed
independently of TICKET-00044/00046/00047 after its real suppliers. Existing architecture/typing work retains its
own acceptance; no blanket EPIC-00015/00016/Common wait or existing TASK scope expansion follows.

[TICKET-00048](00048-TICKET.md) owns the cross-family composition/conformance guide and documents when Agent OS can
retire RoleMutationUnitOfWork, RoleMutationAudit and PostgresRoleMutationAudit. Here own actual package insertion,
fences and family tests, not sibling edits or actual consumer deletion. Refresh all 11 handler paths and shared
coordinator ownership during TASK planning, assigning complete affected caller paths and genuine dependencies.

## Acceptance Evidence

- [ ] All 11 handlers explicitly support enabled/disabled audit with correct actor admission and no implicit
  fallback identity, permission grant, transport lookup or global interception dependency.
- [ ] Role create/rename/remove prove actual incarnation/revisions and reference/CAS safety; User memberships and
  Agent complete-set/direct assignments prove typed relationships, current eligibility and authoritative revisions.
- [ ] Ordinary shared transaction fixtures prove atomic evidence/state, rollback on audit failure/concurrent loss,
  no evidence for validated no-change/rejected work, and committed evidence despite post-commit publication failure.
- [ ] Agent shared assignment orchestration has one actual transaction/evidence owner; neither helper execution nor
  independent inner commits duplicate or escape the required atomic record.
- [ ] Safe evidence and current Commands/Events preserve supported round trips without credential/payload/exception
  capture. Package fixtures are explicitly distinguished from real consumer storage/connection qualification.
- [ ] Every executable slice includes focused/regression-first checks as applicable, canonical full gate/exact owned
  statement coverage, current callers/docs, complete local evidence and independent Review/behavioral QA.

## Exclusions

New permission policy/grants, Domain invariant redesign, generic audit/transaction decorators, repository/event
interception, Agent credential issuance/conversion, consumer persistence/schema mutation or workaround deletion,
new audit Queries/reporting, compatibility/backfill, release and deployment.

## Approved TASK Plan and Coverage

John approved the complete five-TASK plan, now written as kind chore with orders 408–412:

| TASK | Owned outcome | Baseline handler coverage | Direct blocker |
| --- | --- | --- | --- |
| [TASK-00153](../tasks/00153-TASK.md) | Custom Role creation, rename and removal | CreateCustomRole, RenameCustomRole, RemoveCustomRole (3). | TASK-00122 |
| [TASK-00154](../tasks/00154-TASK.md) | Custom Role Permission changes | GrantPermissionToCustomRole, RevokePermissionFromCustomRole (2). | TASK-00122 |
| [TASK-00155](../tasks/00155-TASK.md) | User Role membership | AssignRoleToUser, RemoveRoleFromUser (2). | TASK-00122 |
| [TASK-00156](../tasks/00156-TASK.md) | Agent name updates | UpdateAgent (1). | TASK-00122 |
| [TASK-00157](../tasks/00157-TASK.md) | Direct Agent Permission assignments and actual coordinator transaction ownership | GrantPermissionToAgent, RevokePermissionFromAgent, ReplaceAgentPermissions (3). | TASK-00122 |

At plan approval all five were ready-for-agent because scope was settled, each consuming TASK-00122 and inheriting
TASK-00121. TASK-00153 and TASK-00154 now have independent acceptance and required QA; the other three remain
unfinished. No mutual blocker is demonstrated. Orders are preference, not a serial dependency, and no earlier
scope/order/blockers change.
The allocation is 3 + 2 + 2 + 1 + 3 = all 11 current handlers without overlap/omission. The shared Agent assignment
coordinator belongs only to TASK-00157 and is not another handler. Refresh actual additions/moves/removals at execution;
manifest counts are planning ownership, not completed source audit or transaction/type/consumer qualification.

All 11 current paths already carry typed originating provenance: required UserId actors or UpdateAgent's required
UserId|AgentId initiator. Reuse that data directly, including AgentUpdateInitiator::getId(), rather than introduce
nullable actors, new wrappers, label parsing, ambient identity lookup or fallback identities. Existing required inputs
remain required when audit is disabled. Attribution grants no authority or new eligibility; ADR 0010 and retained
entry/target/scope, reserved identity, tier/reference, lifecycle/cohort and CAS fences remain intact.

Each slice adds the optional last nullable audit repository and necessary cohesive action-specific evidence/current
callers/fixtures/docs together. Explicit handler coordination adds evidence inside the actual mutation transaction
before commit, never by inspecting a returned Event, decorating the UoW or intercepting writes. TASK-00157 reconciles
helper-owned transactions so no inner commit or duplicate helper record escapes that boundary. Validated no-change
creates no mutation evidence; enabled audit failure cannot downgrade to unaudited success. Preserve primary fault,
uncertainty and ordinary post-commit failure with committed evidence retained, not the issuance warning exception.

Role lifecycle/membership evidence retains complete authoritative RoleRevision/incarnation; creation/removal invent
no predecessor/successor. User membership invents no User revision or Role/authentication mutation. Agent rename
uses the exact persisted changedAt and trusted post-admission time, not synthetic name revisions, stale before-state
or compensating reads for audit facts. Direct Agent assignments retain actual before/after assignment revisions,
complete-set semantics and meaningful relationships. Evidence/context needs explicit bounded safety admission; no
entities, credentials, raw messages, diagnostic capture or automatic public/log disclosure.

Coordinate TASK-00140/00141 and actual Domain/architecture owners once without repeating typing/redesign or adding
blanket Common/EPIC-00015/00016 waits. No new Common mechanism is demonstrated; verify supported/tagged APIs and
minimum/ordinary composition at execution. Native UoW mixed plus matching local @var for known owned callback
results remains sufficient, with no generic supplier, wrapper/result DTO or redundant check. TICKET-00048 retains
cross-family conformance/adoption guidance and actual conditions for consumer workaround retirement, not these
slices' family insertion/tests/docs/full gate/exact coverage/complete evidence/independent Review/behavioral QA.
Apply ADR 0011 without compatibility/backfill; no consumer/Common repair, schema/workaround deletion, new grant/policy,
release or deployment follows. Obtain applicable complete private criteria before implementation/review without
disclosure and choose the execution checkout separately.

## Child Tasks

| Order | TASK ID | Title | Status |
| --- | --- | --- | --- |
| 408 | [TASK-00153](../tasks/00153-TASK.md) | Audit custom Role lifecycle changes | done |
| 409 | [TASK-00154](../tasks/00154-TASK.md) | Audit custom Role Permission changes | done |
| 410 | [TASK-00155](../tasks/00155-TASK.md) | Audit User Role membership changes | in-progress |
| 411 | [TASK-00156](../tasks/00156-TASK.md) | Audit Agent name updates | ready-for-agent |
| 412 | [TASK-00157](../tasks/00157-TASK.md) | Audit direct Agent Permission assignments | ready-for-agent |
## Decisions and Progress

TASK-00153 and TASK-00154 are done for independently reviewed and QA-passed custom Role lifecycle and Permission
behavior. Their owning records and canonical reports retain exact acceptance and verification evidence. The other
three TASKs remain unfinished, so this TICKET and EPIC-00017 stay open. Consumer qualification remains separate.

### Historical planning checkpoints

John approved this requirement in the six-TICKET split, then the complete five-TASK plan now written as
TASK-00153–00157. Each is ready-for-agent with TASK-00122 as its only direct blocker, not implemented or independently
accepted. Earlier scope/order/blockers and first Board selection TASK-00107 remain unchanged.

Planning only: no source/dependency/Common/consumer mutation, product build, new checkout/branch/worktree,
commit/publication, consumer adoption/cleanup, independent acceptance, release or deployment is claimed.

Planning generation/read-only validation passes (221 records, 95 active), alongside documentation links and diff/
new-record whitespace checks. Resolved primary manifests allocate 11/11 handlers as 3 + 2 + 2 + 1 + 3 without overlap/
omission, plus the coordinator solely in TASK-00157. Metadata verifies orders 408–412 and only TASK-00122 as each
direct blocker. This is planning coverage, not a source audit, product build or transaction/type/supplier/consumer
qualification. Source/dependency status is unchanged and the Board still selects TASK-00107 first.

TICKET-00046's subsequent four-TASK plan is approved/written as TASK-00158–00161, each blocked only by TASK-00122.
TICKET-00047's subsequent four-TASK plan is approved/written as TASK-00162–00165, separate from administration insertion.
TICKET-00048's four-TASK plan is now approved/written as TASK-00166–00169, completing all six audit plans, not implementation/qualification.
TICKET-00049's two-TASK declaration plan is now written as TASK-00170/TASK-00171, not implemented/qualified.
TICKET-00050's four-TASK Feature plan is now written as TASK-00172–00175, not implemented/native qualification.
TICKET-00051's four-TASK portable Permission plan is now written as TASK-00176–00179, not implemented/qualified.
TICKET-00052's reduced evidence/gap-closure plan is now TASK-00180/00181, completing EPIC-00018 TASK planning only.
Next: `/skill:next` for Board-based selection. EPIC-00016's six accepted TICKET plans are complete, not
implemented/qualified; added EPIC-00015 Agent
conversion and actual Common supplier planning/qualification remain separate unfinished handoffs. Finish selected
remaining planning phases before Board-based implementation selection.
