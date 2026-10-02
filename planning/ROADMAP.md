# Roadmap

## In progress

| Epic | Target | Current outcome |
| --- | --- | --- |
| [EPIC-00009](epics/00009-EPIC.md) | `v0.5.0` | Package current-contract cleanup, cohort/canonical/restoration safety and final guidance are independently accepted; TASK-00059 QA passes. Real consumer qualification and separately authorized release/adoption/deployment remain open. |

## Current package acceptance and next TASK

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

## Approved Feature planning

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
remain separate. All three TICKETs and seven TASKs are planned; behavioral acceptance and reference/lifecycle evidence
remain outstanding. No implementation, release target, or execution worktree is authorized by this planning handoff.
Existing TASK execution priorities remain unchanged; these records are ordered after the existing portfolio.

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
| [EPIC-00006](epics/00006-EPIC.md) | `v0.3.0` | Published the recoverable credential-delivery contract and migration after exact-commit certification and signing. Consumer upgrades remain separately qualified. |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | Replaced deprecated Fight Common transaction dependencies with the supported `TransactionalUnitOfWork` contract while preserving established behavior. |
| [EPIC-00004](epics/00004-EPIC.md) | `v0.2.0` | Delivered the local consumer-composable OpenAPI schema review candidate; tag, release, and publication remain separate effects. |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | Delivered the final unified User/Agent `SecurityContext` and retry-safe Agent Permission, User Role, and custom-Role Permission changes. |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | Delivered the shared identity, credential, session, authorization, and account-lifecycle package slices; a separate stability decision remains required before release. |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | Delivered Agent HMAC authentication, direct Permission authority, request-scoped Agent resolution, and unified distinct User/Agent current-authority access with exact coverage. |

## Released

| Version | Date | Outcome |
| --- | --- | --- |
| `v0.3.0` | 2026-09-25 | Published recoverable, provider-neutral credential delivery for invitations, password resets, and email changes, with pre-1.0 breaking migration guidance. |
| `v0.2.0` | 2026-09-13 | Published consumer-composable OpenAPI schema components. |
| `v0.1.0` | 2026-09-10 | First public package milestone: framework-neutral User and Agent identity, authentication, session, Role, Permission, managed-policy, and current-authority behavior with exact coverage. |

## Record Status Projection

<!-- generated:epic-status:start -->
| EPIC | Target | Status | Tickets | Tasks |
| --- | --- | --- | --- | --- |
| [EPIC-00001](epics/00001-EPIC.md) | 0.x public-source incubation | done | 1 | 18 |
| [EPIC-00002](epics/00002-EPIC.md) | 0.x | done | 1 | 7 |
| [EPIC-00003](epics/00003-EPIC.md) | 0.x | done | 2 | 6 |
| [EPIC-00004](epics/00004-EPIC.md) | v0.2.0 | done | 1 | 1 |
| [EPIC-00005](epics/00005-EPIC.md) | 0.x | needs-info | 1 | 1 |
| [EPIC-00006](epics/00006-EPIC.md) | v0.3.0 | done | 1 | 3 |
| [EPIC-00007](epics/00007-EPIC.md) | pre-1.0 | done | 1 | 1 |
| [EPIC-00008](epics/00008-EPIC.md) | v0.4.0 | done | 3 | 5 |
| [EPIC-00009](epics/00009-EPIC.md) | v0.5.0 | done | 3 | 14 |
| [EPIC-00010](epics/00010-EPIC.md) | unassigned | in-progress | 3 | 7 |
<!-- generated:epic-status:end -->
